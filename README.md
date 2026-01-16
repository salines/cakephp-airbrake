# CakePHP Airbrake Plugin

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![CakePHP 5.x](https://img.shields.io/badge/CakePHP-5.x-red.svg)](https://cakephp.org)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)

A CakePHP 5.x plugin for [Airbrake](https://airbrake.io/) error tracking and exception monitoring. Automatically captures and reports exceptions, PHP errors, and log messages to Airbrake.

## Features

- Automatic exception and error tracking
- Seamless integration with CakePHP's error handling system
- Log engine for sending log messages to Airbrake
- Request context (URL, HTTP method, user agent, etc.)
- User identification support
- Sensitive data filtering (passwords, tokens, etc.)
- Support for self-hosted Airbrake (Errbit)
- Environment-based configuration

## Requirements

- PHP 8.1 or higher
- CakePHP 5.x
- Airbrake account (or self-hosted Errbit)

## Installation

Install the plugin using Composer:

```bash
composer require salines/cakephp-airbrake
```

## Configuration

### 1. Load the Plugin

Add the plugin to your `src/Application.php`:

```php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Airbrake');
}
```

Or use the CLI:

```bash
bin/cake plugin load Airbrake
```

### 2. Configure Airbrake

Add the Airbrake configuration to your `config/app.php`:

```php
'Airbrake' => [
    'projectId' => env('AIRBRAKE_PROJECT_ID'),
    'projectKey' => env('AIRBRAKE_PROJECT_KEY'),
    'environment' => env('APP_ENV', 'production'),
    'appVersion' => '1.0.0',
    'host' => 'https://api.airbrake.io', // Change for self-hosted
    'enabled' => true,
    'keysBlocklist' => [
        '/password/i',
        '/secret/i',
        '/token/i',
        '/authorization/i',
    ],
],
```

### 3. Configure Error Logger

To automatically send all exceptions and errors to Airbrake, configure the error logger in `config/app.php`:

```php
'Error' => [
    'errorLevel' => E_ALL,
    'exceptionRenderer' => \Cake\Error\Renderer\WebExceptionRenderer::class,
    'skipLog' => [],
    'log' => true,
    'trace' => true,
    'logger' => \Airbrake\Error\AirbrakeErrorLogger::class,
],
```

### 4. Configure Log Engine (Optional)

To send log messages to Airbrake, add the log engine configuration:

```php
'Log' => [
    'airbrake' => [
        'className' => 'Airbrake.Airbrake',
        'projectId' => env('AIRBRAKE_PROJECT_ID'),
        'projectKey' => env('AIRBRAKE_PROJECT_KEY'),
        'environment' => env('APP_ENV', 'production'),
        'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
    ],
],
```

## Environment Variables

You can configure the plugin using environment variables:

```env
AIRBRAKE_PROJECT_ID=123456
AIRBRAKE_PROJECT_KEY=your-project-key
AIRBRAKE_HOST=https://api.airbrake.io
AIRBRAKE_ENABLED=true
APP_ENV=production
```

## Usage

### Automatic Error Tracking

Once configured with the error logger, all uncaught exceptions and PHP errors will automatically be sent to Airbrake.

### Manual Exception Reporting

You can manually send exceptions to Airbrake:

```php
use Airbrake\Notifier;
use Cake\Core\Configure;

try {
    // Your code
} catch (\Exception $e) {
    $notifier = new Notifier(Configure::read('Airbrake'));
    $notifier->notify($e);
}
```

### Using the Log Engine

Send log messages to Airbrake:

```php
use Cake\Log\Log;

Log::error('Something went wrong', ['scope' => 'airbrake']);
Log::critical('Database connection failed');
```

### Adding Custom Context

You can add custom context to your error reports using filters:

```php
use Airbrake\Notifier;
use Cake\Core\Configure;

$notifier = new Notifier(Configure::read('Airbrake'));

$notifier->addFilter(function ($notice) {
    $notice['context']['customField'] = 'customValue';
    $notice['params']['orderId'] = 12345;
    return $notice;
});
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `projectId` | int | null | Your Airbrake project ID (required) |
| `projectKey` | string | null | Your Airbrake project key (required) |
| `environment` | string | 'production' | Environment name |
| `appVersion` | string | null | Application version |
| `host` | string | 'https://api.airbrake.io' | Airbrake API host |
| `enabled` | bool | true | Enable/disable Airbrake reporting |
| `keysBlocklist` | array | [...] | Regex patterns for sensitive keys to filter |
| `rootDirectory` | string | ROOT | Root directory for backtrace filtering |

## Self-Hosted Airbrake (Errbit)

To use with a self-hosted Airbrake server like [Errbit](https://github.com/errbit/errbit):

```php
'Airbrake' => [
    'projectId' => 1,
    'projectKey' => 'your-api-key',
    'host' => 'https://your-errbit-server.com',
    // ... other options
],
```

## Filtering Sensitive Data

The plugin automatically filters sensitive data based on the `keysBlocklist` configuration. By default, it filters keys matching:

- `/password/i`
- `/secret/i`
- `/token/i`
- `/authorization/i`

You can add your own patterns:

```php
'keysBlocklist' => [
    '/password/i',
    '/secret/i',
    '/credit_card/i',
    '/ssn/i',
],
```

## Disabling in Development

You can disable Airbrake in development:

```php
'Airbrake' => [
    // ... other config
    'enabled' => !Configure::read('debug'),
],
```

Or using environment variables:

```env
AIRBRAKE_ENABLED=false
```

## Testing

Run the tests:

```bash
composer test
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

- [Airbrake](https://airbrake.io/) - Error monitoring service
- [PHPBrake](https://github.com/airbrake/phpbrake) - Official Airbrake PHP library
- [CakePHP](https://cakephp.org/) - PHP framework
