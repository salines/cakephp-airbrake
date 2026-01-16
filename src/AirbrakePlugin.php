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
                'projectId' => null,
                'projectKey' => null,
                'environment' => env('APP_ENV', 'production'),
                'appVersion' => null,
                'host' => 'https://api.airbrake.io',
                'enabled' => true,
                'keysBlocklist' => ['/password/i', '/secret/i', '/token/i', '/authorization/i'],
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
        $container->addShared(\Airbrake\Notifier::class, function () {
            $config = Configure::read('Airbrake');

            if (empty($config['projectId']) || empty($config['projectKey'])) {
                throw new \RuntimeException(
                    'Airbrake projectId and projectKey are required. ' .
                    'Please configure them in config/app.php under the "Airbrake" key.'
                );
            }

            $notifier = new \Airbrake\Notifier([
                'projectId' => $config['projectId'],
                'projectKey' => $config['projectKey'],
                'environment' => $config['environment'] ?? 'production',
                'appVersion' => $config['appVersion'] ?? null,
                'host' => $config['host'] ?? 'https://api.airbrake.io',
                'keysBlocklist' => $config['keysBlocklist'] ?? [],
            ]);

            return $notifier;
        });

        // Register the error logger
        $container->addShared(AirbrakeErrorLogger::class, function () use ($container) {
            return new AirbrakeErrorLogger(
                Configure::read('Airbrake')
            );
        });
    }
}
