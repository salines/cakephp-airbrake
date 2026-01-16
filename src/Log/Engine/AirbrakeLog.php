<?php
declare(strict_types=1);

namespace Airbrake\Log\Engine;

use Airbrake\Notifier;
use Cake\Log\Engine\BaseLog;
use Stringable;

/**
 * Airbrake Log Engine
 *
 * A CakePHP log engine that sends log messages to Airbrake.
 * Useful for capturing warnings, errors, and critical messages.
 */
class AirbrakeLog extends BaseLog
{
    /**
     * Airbrake Notifier instance.
     *
     * @var \Airbrake\Notifier|null
     */
    protected ?Notifier $notifier = null;

    /**
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'projectId' => null,
        'projectKey' => null,
        'environment' => 'production',
        'appVersion' => null,
        'host' => 'https://api.airbrake.io',
        'enabled' => true,
        'keysBlocklist' => ['/password/i', '/secret/i', '/token/i', '/authorization/i'],
        'rootDirectory' => null,
        'levels' => [],
        'scopes' => [],
    ];

    /**
     * Get the Airbrake Notifier instance.
     *
     * @return \Airbrake\Notifier|null
     */
    protected function getNotifier(): ?Notifier
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($this->notifier === null) {
            $config = $this->getConfig();

            if (empty($config['projectId']) || empty($config['projectKey'])) {
                return null;
            }

            $this->notifier = new Notifier([
                'projectId' => $config['projectId'],
                'projectKey' => $config['projectKey'],
                'environment' => $config['environment'],
                'appVersion' => $config['appVersion'],
                'host' => $config['host'],
                'keysBlocklist' => $config['keysBlocklist'],
                'rootDirectory' => $config['rootDirectory'] ?? (defined('ROOT') ? ROOT : null),
            ]);

            // Add filter to mark as log entry
            $this->notifier->addFilter(function ($notice) {
                $notice['context']['component'] = 'cakephp-log';
                return $notice;
            });
        }

        return $this->notifier;
    }

    /**
     * Check if Airbrake logging is enabled.
     *
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return (bool)$this->getConfig('enabled', true);
    }

    /**
     * Implements log method.
     *
     * @param mixed $level The severity level of the message.
     * @param \Stringable|string $message The message to be logged.
     * @param array $context Context data for the log message.
     * @return void
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $notifier = $this->getNotifier();
        if ($notifier === null) {
            return;
        }

        $message = $this->interpolate($message, $context);

        // Create an exception to capture the stack trace
        $exception = new \Exception((string)$message);

        $notice = $notifier->buildNotice($exception);

        // Set severity based on log level
        $notice['context']['severity'] = $this->mapLevelToSeverity($level);
        $notice['context']['logLevel'] = $level;

        // Add scope information if available
        if (!empty($context['scope'])) {
            $notice['context']['scope'] = $context['scope'];
        }

        // Add any additional context
        if (!empty($context)) {
            $notice['params'] = array_merge(
                $notice['params'] ?? [],
                ['context' => $this->filterContext($context)]
            );
        }

        $notifier->sendNotice($notice);
    }

    /**
     * Interpolate placeholders in the message.
     *
     * @param \Stringable|string $message The message with placeholders.
     * @param array $context The context data.
     * @return string
     */
    protected function interpolate(Stringable|string $message, array $context): string
    {
        $message = (string)$message;

        if (empty($context)) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($key) && !is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Map CakePHP log level to Airbrake severity.
     *
     * @param mixed $level The log level.
     * @return string
     */
    protected function mapLevelToSeverity(mixed $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical' => 'critical',
            'error' => 'error',
            'warning' => 'warning',
            'notice', 'info' => 'info',
            'debug' => 'debug',
            default => 'error',
        };
    }

    /**
     * Filter sensitive keys from context.
     *
     * @param array $context The context data.
     * @return array
     */
    protected function filterContext(array $context): array
    {
        $filtered = [];
        $blocklist = $this->getConfig('keysBlocklist', []);

        foreach ($context as $key => $value) {
            // Skip scope as it's handled separately
            if ($key === 'scope') {
                continue;
            }

            $isBlocked = false;
            foreach ($blocklist as $pattern) {
                if (preg_match($pattern, $key)) {
                    $isBlocked = true;
                    break;
                }
            }

            if ($isBlocked) {
                $filtered[$key] = '[FILTERED]';
            } elseif (is_array($value)) {
                $filtered[$key] = $this->filterContext($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
