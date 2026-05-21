<?php
/**
 * Example Routes Configuration
 *
 * INSTRUCTIONS:
 * Add these routes to your config/routes.php file
 * (or merge with existing routes if you already have some)
 */

return [
    // Plugins Index
    // URL: /plugins
    'plugins' => ['template' => 'plugins/index'],

    // Plugin Detail Page
    // URL: /plugins/translation-manager
    'plugins/<handle:{slug}>' => ['template' => 'plugins/_plugin'],

    // Changelog Page
    // URL: /plugins/translation-manager/changelog
    'plugins/<handle:{slug}>/changelog' => ['template' => 'plugins/_changelog'],

    // Documentation Page
    // URL: /plugins/translation-manager/docs/installation
    'plugins/<handle:{slug}>/docs/<slug:{slug}>' => ['template' => 'plugins/_doc'],

    // Add your other site routes below...
];
