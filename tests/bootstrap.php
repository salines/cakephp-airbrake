<?php
declare(strict_types=1);

/**
 * Test bootstrap file for Airbrake plugin.
 */

// Define ROOT constant for tests
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

require dirname(__DIR__) . '/vendor/autoload.php';
