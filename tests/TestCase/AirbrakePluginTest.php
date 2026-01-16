<?php
declare(strict_types=1);

namespace Airbrake\Test\TestCase;

use Airbrake\AirbrakePlugin;
use PHPUnit\Framework\TestCase;

/**
 * AirbrakePlugin Test Case
 */
class AirbrakePluginTest extends TestCase
{
    /**
     * Test plugin name.
     *
     * @return void
     */
    public function testPluginName(): void
    {
        $plugin = new AirbrakePlugin();

        $this->assertSame('Airbrake', $plugin->getName());
    }

    /**
     * Test plugin path.
     *
     * @return void
     */
    public function testPluginPath(): void
    {
        $plugin = new AirbrakePlugin();
        $path = $plugin->getPath();

        $this->assertStringEndsWith('cakephp-airbrake' . DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Test plugin has correct hook settings.
     *
     * @return void
     */
    public function testPluginHooks(): void
    {
        $plugin = new AirbrakePlugin();

        // Bootstrap should be enabled
        $this->assertTrue($plugin->isEnabled('bootstrap'));

        // Services should be enabled
        $this->assertTrue($plugin->isEnabled('services'));

        // Routes should be disabled
        $this->assertFalse($plugin->isEnabled('routes'));

        // Middleware should be disabled
        $this->assertFalse($plugin->isEnabled('middleware'));

        // Console should be disabled
        $this->assertFalse($plugin->isEnabled('console'));
    }
}
