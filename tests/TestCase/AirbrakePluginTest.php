<?php
declare(strict_types=1);

namespace CakeAirbrake\Test\TestCase;

use CakeAirbrake\CakeAirbrakePlugin;
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
        $plugin = new CakeAirbrakePlugin();

        $this->assertSame('CakeAirbrake', $plugin->getName());
    }

    /**
     * Test plugin path points to an existing directory.
     *
     * @return void
     */
    public function testPluginPath(): void
    {
        $plugin = new CakeAirbrakePlugin();
        $path = $plugin->getPath();

        $this->assertDirectoryExists($path);
    }

    /**
     * Test plugin has correct hook settings.
     *
     * @return void
     */
    public function testPluginHooks(): void
    {
        $plugin = new CakeAirbrakePlugin();

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
