<?php
/**
 * Docs Manager config.php
 *
 * Configuration file for Docs Manager plugin
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'docs-manager.php'
 * and make your changes there to override default settings.
 *
 * @since 5.0.0
 */

use craft\helpers\App;

return [
    // Global settings
    '*' => [
        // General Settings
        'pluginName' => 'Docs Manager',
        'logLevel' => 'error',

        // Source Settings (defaults for new plugins)
        'defaultSourceType' => 'local', // local, github-api
        'localPluginBasePath' => '@root/plugins',
        'githubToken' => App::env('GITHUB_TOKEN'),

        // Sync Settings
        'autoSync' => false,
        'syncSchedule' => 'daily', // hourly, daily, weekly, monthly

        // Parser Settings
        'enableSyntaxHighlighting' => true,
        'enableAnchorGeneration' => true,

        // Display Settings
        'itemsPerPage' => 50,
    ],

    // Dev environment settings
    'dev' => [
        'logLevel' => 'debug',
        'defaultSourceType' => 'local',
        'localPluginBasePath' => '@root/plugins',
    ],

    // Staging environment settings
    'staging' => [
        'logLevel' => 'info',
        'defaultSourceType' => 'local',
    ],

    // Production environment settings
    'production' => [
        'logLevel' => 'error',
        'defaultSourceType' => 'github-api',
        'autoSync' => true,
        'syncSchedule' => 'daily',
    ],
];
