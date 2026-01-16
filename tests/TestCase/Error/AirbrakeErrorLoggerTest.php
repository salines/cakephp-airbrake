<?php
declare(strict_types=1);

namespace Airbrake\Test\TestCase\Error;

use Airbrake\Error\AirbrakeErrorLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AirbrakeErrorLogger Test Case
 */
class AirbrakeErrorLoggerTest extends TestCase
{
    /**
     * Test that logger is disabled when enabled is false.
     *
     * @return void
     */
    public function testIsDisabledWhenEnabledIsFalse(): void
    {
        $logger = new AirbrakeErrorLogger([
            'enabled' => false,
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('isEnabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($logger));
    }

    /**
     * Test that logger is enabled by default.
     *
     * @return void
     */
    public function testIsEnabledByDefault(): void
    {
        $logger = new AirbrakeErrorLogger([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('isEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($logger));
    }

    /**
     * Test error level to severity mapping.
     *
     * @return void
     */
    public function testMapErrorLevelToSeverity(): void
    {
        $logger = new AirbrakeErrorLogger([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('mapErrorLevelToSeverity');
        $method->setAccessible(true);

        $this->assertSame('error', $method->invoke($logger, E_ERROR));
        $this->assertSame('error', $method->invoke($logger, E_USER_ERROR));
        $this->assertSame('warning', $method->invoke($logger, E_WARNING));
        $this->assertSame('warning', $method->invoke($logger, E_USER_WARNING));
        $this->assertSame('notice', $method->invoke($logger, E_NOTICE));
        $this->assertSame('notice', $method->invoke($logger, E_USER_NOTICE));
        $this->assertSame('warning', $method->invoke($logger, E_DEPRECATED));
        $this->assertSame('info', $method->invoke($logger, E_STRICT));
    }

    /**
     * Test error level name conversion.
     *
     * @return void
     */
    public function testGetErrorLevelName(): void
    {
        $logger = new AirbrakeErrorLogger([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('getErrorLevelName');
        $method->setAccessible(true);

        $this->assertSame('E_ERROR', $method->invoke($logger, E_ERROR));
        $this->assertSame('E_WARNING', $method->invoke($logger, E_WARNING));
        $this->assertSame('E_NOTICE', $method->invoke($logger, E_NOTICE));
        $this->assertSame('E_DEPRECATED', $method->invoke($logger, E_DEPRECATED));
        $this->assertSame('E_UNKNOWN', $method->invoke($logger, 99999));
    }

    /**
     * Test that notifier returns null when credentials are missing.
     *
     * @return void
     */
    public function testGetNotifierReturnsNullWithoutCredentials(): void
    {
        $logger = new AirbrakeErrorLogger([
            'enabled' => true,
            'projectId' => null,
            'projectKey' => null,
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('getNotifier');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($logger));
    }

    /**
     * Test that notifier returns null when disabled.
     *
     * @return void
     */
    public function testGetNotifierReturnsNullWhenDisabled(): void
    {
        $logger = new AirbrakeErrorLogger([
            'enabled' => false,
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('getNotifier');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($logger));
    }

    /**
     * Test that notifier is created with valid credentials.
     *
     * @return void
     */
    public function testGetNotifierCreatesNotifierWithValidCredentials(): void
    {
        $logger = new AirbrakeErrorLogger([
            'enabled' => true,
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $reflection = new ReflectionClass($logger);
        $method = $reflection->getMethod('getNotifier');
        $method->setAccessible(true);

        $notifier = $method->invoke($logger);

        $this->assertInstanceOf(\Airbrake\Notifier::class, $notifier);
    }

    /**
     * Test configuration override.
     *
     * @return void
     */
    public function testConfigurationOverride(): void
    {
        $logger = new AirbrakeErrorLogger([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'environment' => 'staging',
            'host' => 'https://custom.airbrake.io',
            'appVersion' => '2.0.0',
        ]);

        $reflection = new ReflectionClass($logger);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);

        $config = $property->getValue($logger);

        $this->assertSame('staging', $config['environment']);
        $this->assertSame('https://custom.airbrake.io', $config['host']);
        $this->assertSame('2.0.0', $config['appVersion']);
    }
}
