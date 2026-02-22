<?php
declare(strict_types=1);

namespace CakeAirbrake\Test\TestCase;

use CakeAirbrake\Notifier;
use Exception;
use InvalidArgumentException;
use Cake\Cache\Exception\InvalidArgumentException as CacheInvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Notifier Test Case
 */
class NotifierTest extends TestCase
{
    /**
     * Test that constructor throws exception without required config.
     *
     * @return void
     */
    public function testConstructorThrowsWithoutConfig(): void
    {
        $this->expectException(\Throwable::class);

        new Notifier([]);
    }

    /**
     * Test that constructor throws exception with missing projectKey.
     *
     * @return void
     */
    public function testConstructorThrowsWithMissingProjectKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Notifier(['projectId' => 12345]);
    }

    /**
     * Test that constructor throws exception with missing projectId.
     *
     * @return void
     */
    public function testConstructorThrowsWithMissingProjectId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Notifier(['projectKey' => 'test-key']);
    }

    /**
     * Test successful construction with valid config.
     *
     * @return void
     */
    public function testConstructorWithValidConfig(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $this->assertInstanceOf(Notifier::class, $notifier);
    }

    /**
     * Test getConfig returns all config when no key specified.
     *
     * @return void
     */
    public function testGetConfigReturnsAllConfig(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'environment' => 'testing',
        ]);

        $config = $notifier->getConfig();

        $this->assertSame(12345, $config['projectId']);
        $this->assertSame('test-key', $config['projectKey']);
        $this->assertSame('testing', $config['environment']);
    }

    /**
     * Test getConfig returns specific key value.
     *
     * @return void
     */
    public function testGetConfigReturnsSpecificKey(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'environment' => 'testing',
        ]);

        $this->assertSame(12345, $notifier->getConfig('projectId'));
        $this->assertSame('test-key', $notifier->getConfig('projectKey'));
        $this->assertSame('testing', $notifier->getConfig('environment'));
    }

    /**
     * Test getConfig returns default for missing key.
     *
     * @return void
     */
    public function testGetConfigReturnsDefault(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $this->assertNull($notifier->getConfig('nonexistent'));
        $this->assertSame('default', $notifier->getConfig('nonexistent', 'default'));
    }

    /**
     * Test buildNotice creates proper notice structure.
     *
     * @return void
     */
    public function testBuildNoticeStructure(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'environment' => 'testing',
        ]);

        $exception = new Exception('Test error message');
        $notice = $notifier->buildNotice($exception);

        $this->assertArrayHasKey('errors', $notice);
        $this->assertArrayHasKey('context', $notice);
        $this->assertArrayHasKey('environment', $notice);
        $this->assertArrayHasKey('params', $notice);
        $this->assertArrayHasKey('session', $notice);
    }

    /**
     * Test buildNotice captures exception details.
     *
     * @return void
     */
    public function testBuildNoticeCapturesExceptionDetails(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $exception = new RuntimeException('Test runtime error');
        $notice = $notifier->buildNotice($exception);

        $this->assertCount(1, $notice['errors']);
        $this->assertSame('RuntimeException', $notice['errors'][0]['type']);
        $this->assertSame('Test runtime error', $notice['errors'][0]['message']);
        $this->assertArrayHasKey('backtrace', $notice['errors'][0]);
    }

    /**
     * Test buildNotice captures chained exceptions.
     *
     * @return void
     */
    public function testBuildNoticeCapturesChainedExceptions(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $previous = new InvalidArgumentException('Previous error');
        $exception = new RuntimeException('Main error', 0, $previous);
        $notice = $notifier->buildNotice($exception);

        $this->assertCount(2, $notice['errors']);
        $this->assertSame('RuntimeException', $notice['errors'][0]['type']);
        $this->assertSame('InvalidArgumentException', $notice['errors'][1]['type']);
    }

    /**
     * Test buildNotice includes context information.
     *
     * @return void
     */
    public function testBuildNoticeIncludesContext(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'environment' => 'testing',
            'appVersion' => '1.0.0',
        ]);

        $notice = $notifier->buildNotice(new Exception('Test'));

        $this->assertSame('testing', $notice['context']['environment']);
        $this->assertSame('1.0.0', $notice['context']['version']);
        $this->assertArrayHasKey('notifier', $notice['context']);
        $this->assertSame('cakephp-airbrake', $notice['context']['notifier']['name']);
    }

    /**
     * Test addFilter adds filter to the notifier.
     *
     * @return void
     */
    public function testAddFilter(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
        ]);

        $result = $notifier->addFilter(function ($notice) {
            $notice['context']['custom'] = 'value';

            return $notice;
        });

        $this->assertSame($notifier, $result);
    }

    /**
     * Test sensitive data filtering.
     *
     * @return void
     */
    public function testSensitiveDataFiltering(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'keysBlocklist' => ['/password/i', '/secret/i'],
        ]);

        $reflection = new ReflectionClass($notifier);
        $method = $reflection->getMethod('filterArray');
        $method->setAccessible(true);

        $data = [
            'username' => 'john',
            'password' => 'secret123',
            'secret_key' => 'abc123',
        ];

        $filtered = $method->invoke($notifier, $data, ['/password/i', '/secret/i']);

        $this->assertSame('john', $filtered['username']);
        $this->assertSame('[FILTERED]', $filtered['password']);
        $this->assertSame('[FILTERED]', $filtered['secret_key']);
    }

    /**
     * Test isEnabled returns correct value.
     *
     * @return void
     */
    public function testIsEnabled(): void
    {
        $enabledNotifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'enabled' => true,
        ]);

        $disabledNotifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'enabled' => false,
        ]);

        $reflectionEnabled = new ReflectionClass($enabledNotifier);
        $method = $reflectionEnabled->getMethod('isEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($enabledNotifier));
        $this->assertFalse($method->invoke($disabledNotifier));
    }

    /**
     * Test sendNotice returns error when disabled.
     *
     * @return void
     */
    public function testSendNoticeReturnsErrorWhenDisabled(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'enabled' => false,
        ]);

        $notice = $notifier->buildNotice(new Exception('Test'));
        $result = $notifier->sendNotice($notice);

        $this->assertArrayHasKey('error', $result);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * Test root directory filtering in backtrace.
     *
     * @return void
     */
    public function testRootDirectoryFiltering(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'rootDirectory' => '/var/www/app',
        ]);

        $reflection = new ReflectionClass($notifier);
        $method = $reflection->getMethod('filterRootDirectory');
        $method->setAccessible(true);

        $filtered = $method->invoke($notifier, '/var/www/app/src/Controller/AppController.php');

        $this->assertSame('[PROJECT_ROOT]/src/Controller/AppController.php', $filtered);
    }

    /**
     * Test buildNoticesUrl creates correct URL.
     *
     * @return void
     */
    public function testBuildNoticesUrl(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'host' => 'https://api.airbrake.io',
        ]);

        $reflection = new ReflectionClass($notifier);
        $method = $reflection->getMethod('buildNoticesUrl');
        $method->setAccessible(true);

        $url = $method->invoke($notifier);

        $this->assertSame('https://api.airbrake.io/api/v3/projects/12345/notices', $url);
    }

    /**
     * Test buildNoticesUrl adds https if missing.
     *
     * @return void
     */
    public function testBuildNoticesUrlAddsHttps(): void
    {
        $notifier = new Notifier([
            'projectId' => 12345,
            'projectKey' => 'test-key',
            'host' => 'api.airbrake.io',
        ]);

        $reflection = new ReflectionClass($notifier);
        $method = $reflection->getMethod('buildNoticesUrl');
        $method->setAccessible(true);

        $url = $method->invoke($notifier);

        $this->assertStringStartsWith('https://', $url);
    }
}
