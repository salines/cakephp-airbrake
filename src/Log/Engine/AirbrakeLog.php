<?php
declare(strict_types=1);

namespace CakeAirbrake\Log\Engine;

use Cake\Core\Configure;
use Cake\Log\Engine\BaseLog;
use CakeAirbrake\Notifier;
use Exception;
use InvalidArgumentException;
use Stringable;
use Throwable;

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
     * @var \CakeAirbrake\Notifier|null
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
     * @return \CakeAirbrake\Notifier|null
     */
    protected function getNotifier(): ?Notifier
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($this->notifier === null) {
            // Merge with global Airbrake config
            $globalConfig = Configure::read('Airbrake') ?? [];
            $config = array_merge($globalConfig, $this->getConfig());

            if (empty($config['projectId']) || empty($config['projectKey'])) {
                return null;
            }

            try {
                $this->notifier = new Notifier($config);
            } catch (InvalidArgumentException $e) {
                return null;
            }
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
     * @param array<string, mixed> $context Context data for the log message.
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $notifier = $this->getNotifier();
        if ($notifier === null) {
            return;
        }

        $message = $this->interpolate($message, $context);

        // Check if there's an exception in context
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $notice = $notifier->buildNotice($context['exception']);
            unset($context['exception']);
        } else {
            // Create an exception to capture the stack trace
            $exception = new Exception((string)$message);
            $notice = $notifier->buildNotice($exception);
        }

        // Set error type as channel.LEVEL format
        $notice['errors'][0]['type'] = 'cakephp.' . strtoupper((string)$level);

        // Set severity based on log level
        $notice['context']['severity'] = $this->mapLevelToSeverity($level);

        // Add scope information if available
        if (!empty($context['scope'])) {
            $notice['context']['scope'] = $context['scope'];
            unset($context['scope']);
        }

        // Add any additional context as params
        if (!empty($context)) {
            $notice['params']['context'] = $context;
        }

        $notifier->sendNotice($notice);
    }

    /**
     * Interpolate placeholders in the message.
     *
     * @param \Stringable|string $message The message with placeholders.
     * @param array<string, mixed> $context The context data.
     */
    protected function interpolate(Stringable|string $message, array $context = []): string
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
}
