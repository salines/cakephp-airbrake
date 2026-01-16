<?php
declare(strict_types=1);

namespace Airbrake;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Throwable;

/**
 * Airbrake Notifier
 *
 * Native CakePHP implementation for sending error notices to Airbrake API v3.
 */
class Notifier
{
    /**
     * Notifier version.
     */
    public const VERSION = '1.0.0';

    /**
     * Airbrake API v3 notices endpoint.
     */
    protected const API_V3_NOTICES = '/api/v3/projects/%d/notices';

    /**
     * Configuration options.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * HTTP Client instance.
     *
     * @var \Cake\Http\Client
     */
    protected Client $httpClient;

    /**
     * Filters to apply to notices before sending.
     *
     * @var array<callable>
     */
    protected array $filters = [];

    /**
     * Rate limit reset timestamp.
     *
     * @var int
     */
    protected int $rateLimitReset = 0;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration options.
     * @throws \InvalidArgumentException If required config is missing.
     */
    public function __construct(array $config = [])
    {
        $defaultConfig = [
            'projectId' => null,
            'projectKey' => null,
            'host' => 'https://api.airbrake.io',
            'environment' => 'production',
            'appVersion' => null,
            'rootDirectory' => defined('ROOT') ? ROOT : null,
            'keysBlocklist' => [
                '/password/i',
                '/secret/i',
                '/token/i',
                '/authorization/i',
                '/api_key/i',
                '/apikey/i',
                '/access_token/i',
            ],
            'enabled' => true,
            'httpClientOptions' => [
                'timeout' => 10,
            ],
        ];

        $this->config = array_merge($defaultConfig, $config);

        if (empty($this->config['projectId']) || empty($this->config['projectKey'])) {
            throw new \InvalidArgumentException(
                'Airbrake: projectId and projectKey are required configuration options.'
            );
        }

        $this->httpClient = new Client($this->config['httpClientOptions']);

        // Add default filter for sensitive data
        $this->addFilter([$this, 'filterSensitiveData']);
    }

    /**
     * Add a filter to process notices before sending.
     *
     * Filters receive the notice array and should return the modified notice,
     * or null to prevent the notice from being sent.
     *
     * @param callable $filter Filter callback.
     * @return $this
     */
    public function addFilter(callable $filter): static
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Build a notice from an exception.
     *
     * @param \Throwable $exception The exception to build notice from.
     * @return array<string, mixed>
     */
    public function buildNotice(Throwable $exception): array
    {
        $errors = [];
        $exc = $exception;

        while ($exc !== null) {
            $errors[] = [
                'type' => get_class($exc),
                'message' => $exc->getMessage(),
                'backtrace' => $this->buildBacktrace($exc),
            ];
            $exc = $exc->getPrevious();
        }

        $notice = [
            'errors' => $errors,
            'context' => $this->buildContext(),
            'environment' => $this->buildEnvironment(),
            'params' => [],
            'session' => [],
        ];

        return $notice;
    }

    /**
     * Send a notice to Airbrake.
     *
     * @param array<string, mixed> $notice The notice to send.
     * @return array<string, mixed> Notice with 'id' on success or 'error' on failure.
     */
    public function sendNotice(array $notice): array
    {
        if (!$this->isEnabled()) {
            $notice['error'] = 'Airbrake: notifications are disabled';
            return $notice;
        }

        if (time() < $this->rateLimitReset) {
            $notice['error'] = 'Airbrake: IP is rate limited';
            return $notice;
        }

        // Apply filters
        $notice = $this->applyFilters($notice);
        if ($notice === null || isset($notice['error'])) {
            return $notice ?? ['error' => 'Airbrake: notice was filtered'];
        }

        try {
            $response = $this->sendRequest($notice);
            return $this->processResponse($notice, $response);
        } catch (Throwable $e) {
            $notice['error'] = 'Airbrake: ' . $e->getMessage();
            return $notice;
        }
    }

    /**
     * Notify Airbrake about an exception.
     *
     * Shortcut for buildNotice + sendNotice.
     *
     * @param \Throwable $exception The exception to report.
     * @return array<string, mixed>
     */
    public function notify(Throwable $exception): array
    {
        $notice = $this->buildNotice($exception);

        return $this->sendNotice($notice);
    }

    /**
     * Build backtrace from exception.
     *
     * @param \Throwable $exception The exception.
     * @return array<int, array<string, mixed>>
     */
    protected function buildBacktrace(Throwable $exception): array
    {
        $backtrace = [];

        // Add the exception location as the first frame
        if ($exception->getFile() !== '' && $exception->getLine() !== 0) {
            $backtrace[] = [
                'file' => $this->filterRootDirectory($exception->getFile()),
                'line' => $exception->getLine(),
                'function' => '',
            ];
        }

        foreach ($exception->getTrace() as $frame) {
            $function = $frame['function'] ?? '';
            if (isset($frame['class'], $frame['type'])) {
                $function = $frame['class'] . $frame['type'] . $function;
            }

            $backtrace[] = [
                'file' => isset($frame['file']) ? $this->filterRootDirectory($frame['file']) : '[internal]',
                'line' => $frame['line'] ?? 0,
                'function' => $function,
            ];
        }

        return $backtrace;
    }

    /**
     * Build context for the notice.
     *
     * @return array<string, mixed>
     */
    protected function buildContext(): array
    {
        $context = [
            'notifier' => [
                'name' => 'cakephp-airbrake',
                'version' => self::VERSION,
                'url' => 'https://github.com/salines/cakephp-airbrake',
            ],
            'os' => php_uname(),
            'language' => 'PHP ' . phpversion(),
            'severity' => 'error',
        ];

        if (!empty($this->config['environment'])) {
            $context['environment'] = $this->config['environment'];
        }

        if (!empty($this->config['appVersion'])) {
            $context['version'] = $this->config['appVersion'];
        }

        if (!empty($this->config['rootDirectory'])) {
            $context['rootDirectory'] = $this->config['rootDirectory'];
        }

        $hostname = gethostname();
        if ($hostname !== false) {
            $context['hostname'] = $hostname;
        }

        // Add URL if in web context
        if (isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'])) {
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $context['url'] = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        // Add user agent
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $context['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        // Add user IP
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $context['userAddr'] = trim(array_pop($ips));
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $context['userAddr'] = $_SERVER['REMOTE_ADDR'];
        }

        return $context;
    }

    /**
     * Build environment data.
     *
     * @return array<string, mixed>
     */
    protected function buildEnvironment(): array
    {
        $env = [];

        $keys = ['SERVER_NAME', 'SERVER_SOFTWARE', 'DOCUMENT_ROOT', 'REQUEST_METHOD'];
        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                $env[$key] = $_SERVER[$key];
            }
        }

        return $env;
    }

    /**
     * Apply all filters to a notice.
     *
     * @param array<string, mixed> $notice The notice.
     * @return array<string, mixed>|null
     */
    protected function applyFilters(array $notice): ?array
    {
        foreach ($this->filters as $filter) {
            $result = $filter($notice);
            if ($result === null || $result === false) {
                return null;
            }
            $notice = $result;
        }

        return $notice;
    }

    /**
     * Filter sensitive data from notice.
     *
     * @param array<string, mixed> $notice The notice.
     * @return array<string, mixed>
     */
    protected function filterSensitiveData(array $notice): array
    {
        $keysToFilter = ['context', 'params', 'session', 'environment'];
        $blocklist = $this->config['keysBlocklist'] ?? [];

        foreach ($keysToFilter as $key) {
            if (isset($notice[$key]) && is_array($notice[$key])) {
                $notice[$key] = $this->filterArray($notice[$key], $blocklist);
            }
        }

        return $notice;
    }

    /**
     * Recursively filter sensitive keys from array.
     *
     * @param array<string, mixed> $data The data to filter.
     * @param array<string> $blocklist Regex patterns for blocked keys.
     * @return array<string, mixed>
     */
    protected function filterArray(array $data, array $blocklist): array
    {
        foreach ($data as $key => $value) {
            foreach ($blocklist as $pattern) {
                if (is_string($key) && preg_match($pattern, $key)) {
                    $data[$key] = '[FILTERED]';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->filterArray($value, $blocklist);
            }
        }

        return $data;
    }

    /**
     * Filter root directory from file paths.
     *
     * @param string $file The file path.
     * @return string
     */
    protected function filterRootDirectory(string $file): string
    {
        $rootDir = $this->config['rootDirectory'] ?? null;
        if ($rootDir !== null) {
            return str_replace($rootDir, '[PROJECT_ROOT]', $file);
        }

        return $file;
    }

    /**
     * Send HTTP request to Airbrake.
     *
     * @param array<string, mixed> $notice The notice to send.
     * @return \Cake\Http\Client\Response
     */
    protected function sendRequest(array $notice): Response
    {
        $url = $this->buildNoticesUrl();

        return $this->httpClient->post($url, json_encode($notice), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config['projectKey'],
            ],
        ]);
    }

    /**
     * Build the notices API URL.
     *
     * @return string
     */
    protected function buildNoticesUrl(): string
    {
        $host = rtrim($this->config['host'], '/');
        if (!preg_match('~^https?://~i', $host)) {
            $host = 'https://' . $host;
        }

        return $host . sprintf(self::API_V3_NOTICES, (int)$this->config['projectId']);
    }

    /**
     * Process HTTP response from Airbrake.
     *
     * @param array<string, mixed> $notice The original notice.
     * @param \Cake\Http\Client\Response $response The HTTP response.
     * @return array<string, mixed>
     */
    protected function processResponse(array $notice, Response $response): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401) {
            $notice['error'] = 'Airbrake: unauthorized - check projectId and projectKey';
            return $notice;
        }

        if ($statusCode === 429) {
            $delay = $response->getHeader('X-RateLimit-Delay');
            if (!empty($delay)) {
                $this->rateLimitReset = time() + (int)$delay[0];
            }
            $notice['error'] = 'Airbrake: IP is rate limited';
            return $notice;
        }

        $body = json_decode((string)$response->getBody(), true);

        if (isset($body['id'])) {
            $notice['id'] = $body['id'];
            if (isset($body['url'])) {
                $notice['url'] = $body['url'];
            }
            return $notice;
        }

        if (isset($body['message'])) {
            $notice['error'] = 'Airbrake: ' . $body['message'];
            return $notice;
        }

        $notice['error'] = 'Airbrake: unexpected response - ' . (string)$response->getBody();
        return $notice;
    }

    /**
     * Check if notifications are enabled.
     *
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? true);
    }

    /**
     * Get configuration value.
     *
     * @param string|null $key Configuration key or null for all config.
     * @param mixed $default Default value if key not found.
     * @return mixed
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }
}
