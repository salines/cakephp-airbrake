<?php
declare(strict_types=1);

/**
 * Airbrake Plugin Bootstrap
 *
 * This file is loaded automatically by the plugin during bootstrap.
 * You can use it to set default configuration values.
 */

use Cake\Core\Configure;

// Set default Airbrake configuration if not already defined
if (!Configure::check('Airbrake')) {
    Configure::write('Airbrake', [
        // Required: Your Airbrake project ID
        'projectId' => env('AIRBRAKE_PROJECT_ID'),

        // Required: Your Airbrake project key
        'projectKey' => env('AIRBRAKE_PROJECT_KEY'),

        // Environment name (production, staging, development, etc.)
        'environment' => env('APP_ENV', 'production'),

        // Application version for tracking
        'appVersion' => null,

        // Airbrake API host (change for self-hosted or Errbit)
        'host' => env('AIRBRAKE_HOST', 'https://api.airbrake.io'),

        // Enable or disable Airbrake reporting
        'enabled' => filter_var(env('AIRBRAKE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Keys to filter from reports (regex patterns)
        'keysBlocklist' => [
            '/password/i',
            '/secret/i',
            '/token/i',
            '/authorization/i',
            '/api_key/i',
            '/apikey/i',
            '/access_token/i',
            '/credit_card/i',
            '/card_number/i',
            '/cvv/i',
        ],

        // Root directory for backtrace filtering
        'rootDirectory' => defined('ROOT') ? ROOT : null,
    ]);
}
