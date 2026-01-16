<?php
declare(strict_types=1);

namespace Airbrake;

use Airbrake\Error\AirbrakeErrorLogger;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;

/**
 * Airbrake Plugin
 *
 * CakePHP 5.x plugin for Airbrake error tracking and exception monitoring.
 * Native implementation using Airbrake API v3 - no external dependencies required.
 */
class AirbrakePlugin extends BasePlugin
{
    /**
     * Plugin name.
     *
     * @var string|null
     */
    protected ?string $name = 'Airbrake';

    /**
     * Do bootstrapping or not
     *
     * @var bool
     */
    protected bool $bootstrapEnabled = true;

    /**
     * Load routes or not
     *
     * @var bool
     */
    protected bool $routesEnabled = false;

    /**
     * Enable middleware
     *
     * @var bool
     */
    protected bool $middlewareEnabled = false;

    /**
     * Console middleware
     *
     * @var bool
     */
    protected bool $consoleEnabled = false;

    /**
     * Enable services
     *
     * @var bool
     */
    protected bool $servicesEnabled = true;

    /**
     * Bootstrap hook.
     *
     * Loads plugin configuration and sets up the error logger.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The application instance.
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        // Load default configuration if not already set
        if (!Configure::check('Airbrake')) {
            Configure::write('Airbrake', [
                'projectId' => env('AIRBRAKE_PROJECT_ID'),
                'projectKey' => env('AIRBRAKE_PROJECT_KEY'),
                'environment' => env('APP_ENV', 'production'),
                'appVersion' => null,
                'host' => env('AIRBRAKE_HOST', 'https://api.airbrake.io'),
                'enabled' => filter_var(env('AIRBRAKE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
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
            ]);
        }
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The container instance.
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        // Register the Airbrake Notifier as a singleton
        $container->addShared(Notifier::class, function () {
            $config = Configure::read('Airbrake') ?? [];

            return new Notifier($config);
        });

        // Register the error logger
        $container->addShared(AirbrakeErrorLogger::class, function () {
            return new AirbrakeErrorLogger();
        });
    }
}
