<?php
declare(strict_types=1);

namespace Airbrake\Log\Engine;

use Airbrake\Notifier;
use Cake\Core\Configure;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog Handler for Airbrake
 *
 * Allows using Airbrake with Monolog logger.
 * This is a CakePHP-friendly wrapper around Airbrake's MonologHandler.
 *
 * Usage with Monolog:
 * ```php
 * use Monolog\Logger;
 * use Airbrake\Log\Engine\MonologHandler;
 *
 * $log = new Logger('app');
 * $log->pushHandler(new MonologHandler());
 * $log->error('Something went wrong', ['user_id' => 123]);
 * ```
 */
class MonologHandler extends AbstractProcessingHandler
{
    /**
     * Airbrake Notifier instance.
     *
     * @var \Airbrake\Notifier|null
     */
    protected ?Notifier $notifier = null;

    /**
     * Configuration array.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration options.
     * @param \Monolog\Level $level The minimum logging level.
     * @param bool $bubble Whether messages should bubble up the stack.
     */
    public function __construct(
        array $config = [],
        Level $level = Level::Error,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $defaultConfig = Configure::read('Airbrake') ?? [];
        $this->config = array_merge([
            'projectId' => null,
            'projectKey' => null,
            'environment' => 'production',
            'appVersion' => null,
            'host' => 'https://api.airbrake.io',
            'enabled' => true,
            'keysBlocklist' => ['/password/i', '/secret/i', '/token/i', '/authorization/i'],
            'rootDirectory' => defined('ROOT') ? ROOT : null,
        ], $defaultConfig, $config);
    }

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
            if (empty($this->config['projectId']) || empty($this->config['projectKey'])) {
                return null;
            }

            $this->notifier = new Notifier([
                'projectId' => $this->config['projectId'],
                'projectKey' => $this->config['projectKey'],
                'environment' => $this->config['environment'],
                'appVersion' => $this->config['appVersion'],
                'host' => $this->config['host'],
                'keysBlocklist' => $this->config['keysBlocklist'],
                'rootDirectory' => $this->config['rootDirectory'],
            ]);

            $this->notifier->addFilter(function ($notice) {
                $notice['context']['component'] = 'cakephp-monolog';
                return $notice;
            });
        }

        return $this->notifier;
    }

    /**
     * Check if Airbrake is enabled.
     *
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? true);
    }

    /**
     * Writes the record down to the log.
     *
     * @param \Monolog\LogRecord $record The log record.
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        $notifier = $this->getNotifier();
        if ($notifier === null) {
            return;
        }

        $context = $record->context;

        // Check if there's an exception in the context
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $notice = $notifier->buildNotice($context['exception']);
            unset($context['exception']);
        } else {
            // Create an exception from the message
            $notice = $notifier->buildNotice(new \Exception($record->message));
        }

        // Set severity based on log level
        $notice['context']['severity'] = $this->mapLevelToSeverity($record->level);
        $notice['context']['logLevel'] = $record->level->name;
        $notice['context']['channel'] = $record->channel;

        // Add context as params
        if (!empty($context)) {
            $notice['params'] = array_merge(
                $notice['params'] ?? [],
                $this->filterContext($context)
            );
        }

        // Add extra data
        if (!empty($record->extra)) {
            $notice['params']['extra'] = $record->extra;
        }

        $notifier->sendNotice($notice);
    }

    /**
     * Map Monolog level to Airbrake severity.
     *
     * @param \Monolog\Level $level The Monolog level.
     * @return string
     */
    protected function mapLevelToSeverity(Level $level): string
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => 'critical',
            Level::Error => 'error',
            Level::Warning => 'warning',
            Level::Notice, Level::Info => 'info',
            Level::Debug => 'debug',
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
        $blocklist = $this->config['keysBlocklist'] ?? [];

        foreach ($context as $key => $value) {
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
