<?php
declare(strict_types=1);

namespace Airbrake\Error;

use Airbrake\Notifier;
use Cake\Core\Configure;
use Cake\Error\ErrorLoggerInterface;
use Cake\Error\PhpError;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Airbrake Error Logger
 *
 * Implements CakePHP's ErrorLoggerInterface to send errors and exceptions to Airbrake.
 */
class AirbrakeErrorLogger implements ErrorLoggerInterface
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
     */
    public function __construct(array $config = [])
    {
        $defaultConfig = [
            'projectId' => null,
            'projectKey' => null,
            'environment' => 'production',
            'appVersion' => null,
            'host' => 'https://api.airbrake.io',
            'enabled' => true,
            'keysBlocklist' => ['/password/i', '/secret/i', '/token/i', '/authorization/i'],
            'rootDirectory' => ROOT ?? null,
        ];

        $this->config = array_merge($defaultConfig, $config);
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

            // Add filter to include request context
            $this->notifier->addFilter(function ($notice) {
                $notice['context']['component'] = 'cakephp';
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
     * Log a PHP error to Airbrake.
     *
     * @param \Cake\Error\PhpError $error The PHP error instance.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request instance (if available).
     * @param bool $includeTrace Whether to include the stack trace.
     * @return void
     */
    public function logError(
        PhpError $error,
        ?ServerRequestInterface $request = null,
        bool $includeTrace = false
    ): void {
        $notifier = $this->getNotifier();
        if ($notifier === null) {
            return;
        }

        $severity = $this->mapErrorLevelToSeverity($error->getCode());

        // Create an exception-like structure for Airbrake
        $exception = new \ErrorException(
            $error->getMessage(),
            0,
            $error->getCode(),
            $error->getFile(),
            $error->getLine()
        );

        $notice = $notifier->buildNotice($exception);

        // Add additional context
        $notice['context']['severity'] = $severity;
        $notice['context']['errorLevel'] = $this->getErrorLevelName($error->getCode());

        // Add request data if available
        if ($request !== null) {
            $notice = $this->addRequestContext($notice, $request);
        }

        $notifier->sendNotice($notice);
    }

    /**
     * Log an exception to Airbrake.
     *
     * @param \Throwable $exception The exception to log.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request instance (if available).
     * @param bool $includeTrace Whether to include the stack trace.
     * @return void
     */
    public function logException(
        Throwable $exception,
        ?ServerRequestInterface $request = null,
        bool $includeTrace = false
    ): void {
        $notifier = $this->getNotifier();
        if ($notifier === null) {
            return;
        }

        $notice = $notifier->buildNotice($exception);

        // Add request data if available
        if ($request !== null) {
            $notice = $this->addRequestContext($notice, $request);
        }

        $notifier->sendNotice($notice);
    }

    /**
     * Add request context to the notice.
     *
     * @param array $notice The Airbrake notice.
     * @param \Psr\Http\Message\ServerRequestInterface $request The request instance.
     * @return array
     */
    protected function addRequestContext(array $notice, ServerRequestInterface $request): array
    {
        $uri = $request->getUri();

        // Context fields as per Airbrake API v3
        $notice['context']['url'] = (string)$uri;
        $notice['context']['httpMethod'] = $request->getMethod();
        $notice['context']['userAgent'] = $request->getHeaderLine('User-Agent');

        // Add route if available (CakePHP specific)
        $routeParams = $request->getAttribute('params', []);
        if (!empty($routeParams)) {
            $route = '';
            if (!empty($routeParams['prefix'])) {
                $route .= '/' . $routeParams['prefix'];
            }
            if (!empty($routeParams['controller'])) {
                $route .= '/' . $routeParams['controller'];
            }
            if (!empty($routeParams['action'])) {
                $route .= '/' . $routeParams['action'];
            }
            if ($route) {
                $notice['context']['route'] = $route;
            }

            // Set component and action for better grouping in Airbrake
            if (!empty($routeParams['controller'])) {
                $notice['context']['component'] = $routeParams['controller'];
            }
            if (!empty($routeParams['action'])) {
                $notice['context']['action'] = $routeParams['action'];
            }
        }

        // Add user IP address
        $clientIp = $request->clientIp ?? $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if ($clientIp) {
            $notice['context']['userAddr'] = $clientIp;
        }

        // Add request parameters (filtered)
        $notice['params'] = array_merge(
            $notice['params'] ?? [],
            [
                'query' => $request->getQueryParams(),
            ]
        );

        // Add environment variables (server params subset)
        $serverParams = $request->getServerParams();
        $notice['environment'] = array_merge(
            $notice['environment'] ?? [],
            array_filter([
                'REQUEST_METHOD' => $serverParams['REQUEST_METHOD'] ?? null,
                'REQUEST_URI' => $serverParams['REQUEST_URI'] ?? null,
                'SERVER_NAME' => $serverParams['SERVER_NAME'] ?? null,
                'HTTP_HOST' => $serverParams['HTTP_HOST'] ?? null,
            ])
        );

        // Add session data if available
        $session = $request->getAttribute('session');
        if ($session !== null && method_exists($session, 'read')) {
            $sessionData = [];
            if (method_exists($session, 'id')) {
                $sessionData['id'] = $session->id();
            }
            $notice['session'] = $sessionData;
        }

        // Add user context if available (CakePHP Authentication plugin)
        $identity = $request->getAttribute('identity');
        if ($identity !== null) {
            $userData = [
                'id' => $identity->getIdentifier() ?? null,
            ];

            // Try to get additional user info if available
            if (method_exists($identity, 'get')) {
                $name = $identity->get('name') ?? $identity->get('username') ?? null;
                $email = $identity->get('email') ?? null;
                if ($name) {
                    $userData['name'] = $name;
                }
                if ($email) {
                    $userData['email'] = $email;
                }
            }

            $notice['context']['user'] = $userData;
        }

        return $notice;
    }

    /**
     * Map PHP error level to severity string.
     *
     * @param int $level PHP error level.
     * @return string
     */
    protected function mapErrorLevelToSeverity(int $level): string
    {
        return match ($level) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_STRICT => 'info',
            E_DEPRECATED, E_USER_DEPRECATED => 'warning',
            default => 'error',
        };
    }

    /**
     * Get human-readable error level name.
     *
     * @param int $level PHP error level.
     * @return string
     */
    protected function getErrorLevelName(int $level): string
    {
        return match ($level) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'UNKNOWN',
        };
    }
}
