<?php
declare(strict_types=1);

namespace Airbrake\Test\TestCase\Log\Engine;

use Airbrake\Log\Engine\AirbrakeLog;
use Airbrake\Notifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AirbrakeLog Test Case
 */
class AirbrakeLogTest extends TestCase
{
    /**
     * Test default configuration.
     *
     * @return void
     */
    public function testDefaultConfiguration(): void
    {
        $log = new AirbrakeLog([]);

        $this->assertSame('production', $log->getConfig('environment'));
        $this->assertSame('https://api.airbrake.io', $log->getConfig('host'));
        $this->assertTrue($log->getConfig('enabled'));
    }

    /**
     * Test configuration override.
     *
     * @return void
     */
    public function testConfigurationOverride(): void
    {
        $log = new AirbrakeLog([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'environment' => 'staging',
            'host' => 'https://custom.airbrake.io',
        ]);

        $this->assertSame(12345, $log->getConfig('projectId'));
        $this->assertSame('test-key', $log->getConfig('projectKey'));
        $this->assertSame('staging', $log->getConfig('environment'));
        $this->assertSame('https://custom.airbrake.io', $log->getConfig('host'));
    }

    /**
     * Test log level to severity mapping.
     *
     * @return void
     */
    public function testMapLevelToSeverity(): void
    {
        $log = new AirbrakeLog([]);

        $reflection = new ReflectionClass($log);
        $method = $reflection->getMethod('mapLevelToSeverity');
        $method->setAccessible(true);

        $this->assertSame('critical', $method->invoke($log, 'emergency'));
        $this->assertSame('critical', $method->invoke($log, 'alert'));
        $this->assertSame('critical', $method->invoke($log, 'critical'));
        $this->assertSame('error', $method->invoke($log, 'error'));
        $this->assertSame('warning', $method->invoke($log, 'warning'));
        $this->assertSame('info', $method->invoke($log, 'notice'));
        $this->assertSame('info', $method->invoke($log, 'info'));
        $this->assertSame('debug', $method->invoke($log, 'debug'));
        $this->assertSame('error', $method->invoke($log, 'unknown'));
    }

    /**
     * Test message interpolation.
     *
     * @return void
     */
    public function testInterpolate(): void
    {
        $log = new AirbrakeLog([]);

        $reflection = new ReflectionClass($log);
        $method = $reflection->getMethod('interpolate');
        $method->setAccessible(true);

        $message = 'User {name} has ID {id}';
        $context = ['name' => 'John', 'id' => 123];

        $result = $method->invoke($log, $message, $context);
        $this->assertSame('User John has ID 123', $result);
    }

    /**
     * Test message interpolation with empty context.
     *
     * @return void
     */
    public function testInterpolateWithEmptyContext(): void
    {
        $log = new AirbrakeLog([]);

        $reflection = new ReflectionClass($log);
        $method = $reflection->getMethod('interpolate');
        $method->setAccessible(true);

        $message = 'Simple message';
        $result = $method->invoke($log, $message, []);

        $this->assertSame('Simple message', $result);
    }

    /**
     * Test that notifier returns null when disabled.
     *
     * @return void
     */
    public function testGetNotifierReturnsNullWhenDisabled(): void
    {
        $log = new AirbrakeLog([
            'enabled' => false,
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($log);
        $method = $reflection->getMethod('getNotifier');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($log));
    }

    /**
     * Test that notifier returns null without credentials.
     *
     * @return void
     */
    public function testGetNotifierReturnsNullWithoutCredentials(): void
    {
        $log = new AirbrakeLog([
            'enabled' => true,
            'projectId' => null,
            'projectKey' => null,
        ]);

        $reflection = new ReflectionClass($log);
        $method = $reflection->getMethod('getNotifier');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($log));
    }

    /**
     * Test that notifier is created with valid credentials.
     *
     * @return void
     */
    public function testGetNotifierCreatesNotifierWithValidCredentials(): void
    {
        $log = new AirbrakeLog([
            'enabled' => true,
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($log);
        $method = $reflection->getMethod('getNotifier');
        $method->setAccessible(true);

        $notifier = $method->invoke($log);

        $this->assertInstanceOf(Notifier::class, $notifier);
    }

    /**
     * Test isEnabled method.
     *
     * @return void
     */
    public function testIsEnabled(): void
    {
        $logEnabled = new AirbrakeLog(['enabled' => true]);
        $logDisabled = new AirbrakeLog(['enabled' => false]);

        $reflection = new ReflectionClass($logEnabled);
        $method = $reflection->getMethod('isEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($logEnabled));
        $this->assertFalse($method->invoke($logDisabled));
    }
}
