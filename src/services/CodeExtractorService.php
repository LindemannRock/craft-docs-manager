<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\services;

use Craft;
use craft\base\Component;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Code Extractor Service
 *
 * Extracts documentation from PHP source code:
 * - Settings model properties → Configuration reference
 * - Variable class methods → Template variables reference
 * - Console controllers → Console commands reference
 * - Plugin class → Permissions reference
 *
 * @since 5.0.0
 */
class CodeExtractorService extends Component
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('docs-manager');
    }

    /**
     * Extract all documentation from a plugin
     *
     * @param string $pluginPath Path to plugin root
     * @return array ['settings' => [...], 'variables' => [...], 'commands' => [...], 'permissions' => [...]]
     */
    public function extractAll(string $pluginPath): array
    {
        $result = [
            'settings' => [],
            'variables' => [],
            'commands' => [],
            'permissions' => [],
            'events' => [],
            'twigGlobals' => [],
            'sharedFeatures' => [],
            'integrations' => [],
            'graphql' => [],
        ];

        // Extract settings from models/Settings.php
        $settingsPath = $pluginPath . '/src/models/Settings.php';
        if (file_exists($settingsPath)) {
            $result['settings'] = $this->extractSettings($settingsPath);
        }

        // Extract variables from variables/*Variable.php
        $variablesDir = $pluginPath . '/src/variables';
        if (is_dir($variablesDir)) {
            $result['variables'] = $this->extractVariables($variablesDir);
        }

        // Extract console commands from console/controllers/*.php
        $consoleDir = $pluginPath . '/src/console/controllers';
        if (is_dir($consoleDir)) {
            $result['commands'] = $this->extractConsoleCommands($consoleDir, $pluginPath);
        }

        // Extract permissions from main plugin class
        $result['permissions'] = $this->extractPermissions($pluginPath);

        // Extract Twig globals from PluginHelper::bootstrap() usage
        $result['twigGlobals'] = $this->extractTwigGlobals($pluginPath);

        // Extract shared features (base plugin, logging library)
        $result['sharedFeatures'] = $this->extractSharedFeatures($pluginPath);

        // Extract plugin events (EVENT_* constants)
        $result['events'] = $this->extractEvents($pluginPath);

        // Extract integrations (src/integrations/ folder and plugin dependencies)
        $result['integrations'] = $this->extractIntegrations($pluginPath);

        // Extract GraphQL support
        $result['graphql'] = $this->extractGraphQL($pluginPath);

        return $result;
    }

    /**
     * Extract settings/config options from Settings.php
     *
     * @param string $filePath Path to Settings.php
     * @return array Array of setting definitions
     */
    public function extractSettings(string $filePath): array
    {
        $settings = [];
        $content = file_get_contents($filePath);

        // Parse public properties with their docblocks
        // Match: /** @var type description */ public type $name = value;
        $pattern = '/\/\*\*\s*\n((?:\s*\*[^\n]*\n)*?)\s*\*\/\s*\n\s*public\s+(?:(\??\w+)\s+)?\$(\w+)(?:\s*=\s*([^;]+))?;/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $docblock = $match[1];
                $propertyType = !empty($match[2]) ? $match[2] : null;
                $propertyName = $match[3];
                $defaultValue = isset($match[4]) ? trim($match[4]) : null;

                // Extract @var type and description from docblock
                $varType = null;
                $description = '';

                if (preg_match('/@var\s+(\S+)\s+(.*)$/m', $docblock, $varMatch)) {
                    $varType = $varMatch[1];
                    $description = trim($varMatch[2]);
                }

                // Use property type if available, otherwise use @var type
                $type = $propertyType ?: $varType ?: 'mixed';

                // Clean up type
                $type = str_replace('?', '', $type);

                // Clean up default value
                if ($defaultValue !== null) {
                    $defaultValue = trim($defaultValue);
                    // Handle common cases
                    if ($defaultValue === 'null') {
                        $defaultValue = 'null';
                    } elseif ($defaultValue === 'true' || $defaultValue === 'false') {
                        // Keep as-is
                    } elseif (preg_match('/^[\'"](.*)[\'"]\s*$/', $defaultValue, $strMatch)) {
                        $defaultValue = $strMatch[1];
                    } elseif (preg_match('/^\[/', $defaultValue)) {
                        $defaultValue = '[]';
                    }
                }

                $settings[] = [
                    'name' => $propertyName,
                    'type' => $type,
                    'default' => $defaultValue,
                    'description' => $description,
                ];
            }
        }

        return $settings;
    }

    /**
     * Extract template variables from Variable classes
     *
     * @param string $dirPath Path to variables directory
     * @return array Array of variable method definitions
     */
    public function extractVariables(string $dirPath): array
    {
        $variables = [];
        $files = glob($dirPath . '/*Variable.php');

        foreach ($files as $filePath) {
            $content = file_get_contents($filePath);

            // Get class name from file
            if (preg_match('/class\s+(\w+)/', $content, $classMatch)) {
                $className = $classMatch[1];
            } else {
                continue;
            }

            // Extract the variable name (e.g., craft.docsManager)
            $variableName = lcfirst(str_replace('Variable', '', $className));

            // Find all public methods with their docblocks
            $pattern = '/\/\*\*\s*\n(.*?)\*\/\s*\n\s*public\s+function\s+(\w+)\s*\(([^)]*)\)(?:\s*:\s*(\S+))?/s';

            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $docblock = $match[1];
                    $methodName = $match[2];
                    $params = $match[3];
                    $returnType = $match[4] ?? null;

                    // Skip magic methods and init
                    if (str_starts_with($methodName, '__') || $methodName === 'init') {
                        continue;
                    }

                    // Extract description from docblock
                    $description = $this->extractDocblockDescription($docblock);

                    // Extract usage example from docblock
                    $usage = $this->extractDocblockUsage($docblock);

                    // Extract parameters from docblock
                    $parameters = $this->extractDocblockParams($docblock);

                    // Extract return type from docblock if not in signature
                    if (!$returnType) {
                        $returnType = $this->extractDocblockReturn($docblock);
                    }

                    $variables[] = [
                        'class' => $className,
                        'variableName' => $variableName,
                        'method' => $methodName,
                        'description' => $description,
                        'usage' => $usage,
                        'parameters' => $parameters,
                        'returnType' => $returnType,
                    ];
                }
            }
        }

        return $variables;
    }

    /**
     * Extract console commands from console controllers
     *
     * @param string $dirPath Path to console/controllers directory
     * @param string $pluginPath Plugin root path for determining handle
     * @return array Array of command definitions
     */
    public function extractConsoleCommands(string $dirPath, string $pluginPath): array
    {
        $commands = [];

        // Get plugin handle from composer.json
        $composerPath = $pluginPath . '/composer.json';
        $pluginHandle = 'unknown';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $pluginHandle = $composer['extra']['handle'] ?? 'unknown';
        }

        $files = glob($dirPath . '/*Controller.php');

        foreach ($files as $filePath) {
            $content = file_get_contents($filePath);

            // Get controller name from class
            if (preg_match('/class\s+(\w+)Controller/', $content, $classMatch)) {
                $controllerName = $classMatch[1];
                // Convert to kebab-case (e.g., SyncController → sync)
                $controllerSlug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $controllerName));
            } else {
                continue;
            }

            // Extract class-level options
            $classOptions = $this->extractControllerOptions($content);

            // Find all action methods with their docblocks
            $pattern = '/\/\*\*\s*\n(.*?)\*\/\s*\n\s*public\s+function\s+action(\w+)\s*\(([^)]*)\)(?:\s*:\s*(\S+))?/s';

            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $docblock = $match[1];
                    $actionName = $match[2];
                    $params = $match[3];
                    $returnType = $match[4] ?? 'int';

                    // Convert action name to slug (e.g., TestParser → test-parser)
                    $actionSlug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $actionName));

                    // Build command path
                    $commandPath = $pluginHandle . '/' . $controllerSlug;
                    if ($actionSlug !== 'index') {
                        $commandPath .= '/' . $actionSlug;
                    }

                    // Extract description
                    $description = $this->extractDocblockDescription($docblock);

                    // Extract usage example
                    $usage = $this->extractDocblockUsage($docblock);

                    // Extract parameters from method signature
                    $parameters = $this->parseMethodParams($params);

                    $commands[] = [
                        'controller' => $controllerName,
                        'action' => $actionName,
                        'command' => $commandPath,
                        'description' => $description,
                        'usage' => $usage,
                        'parameters' => $parameters,
                        'options' => $classOptions,
                    ];
                }
            }
        }

        return $commands;
    }

    /**
     * Extract permissions from plugin class with nesting and group information
     *
     * Returns a structured array of permission groups. Each group has a name and
     * an array of permissions, where parent permissions contain nested children.
     *
     * @param string $pluginPath Plugin root path
     * @return array Array of permission groups: [['group' => string, 'permissions' => [['handle' => string, 'label' => string, 'nested' => [...]]]]
     */
    public function extractPermissions(string $pluginPath): array
    {
        $groups = [];

        // Find main plugin class
        $srcDir = $pluginPath . '/src';
        $pluginFiles = glob($srcDir . '/*.php');

        foreach ($pluginFiles as $filePath) {
            $content = file_get_contents($filePath);

            // Check if this file registers permissions
            if (!str_contains($content, 'EVENT_REGISTER_PERMISSIONS') &&
                !str_contains($content, 'registerUserPermissions')) {
                continue;
            }

            // Extract the permissions array block from the event handler
            // Look for the 'permissions' => [ ... ] block inside the event registration
            $groups = $this->parsePermissionStructure($content);
        }

        return $groups;
    }

    /**
     * Parse the nested permission structure from PHP source code
     *
     * Identifies parent permissions (those with 'nested' => [...]) and groups them
     * by the comment above each parent (e.g., "// Backends - grouped") or by
     * deriving a group name from the permission handle.
     *
     * @return array Array of permission groups
     */
    private function parsePermissionStructure(string $content): array
    {
        $groups = [];
        $seenHandles = [];

        // Match all top-level permission entries: 'handle:permission' => [
        // These are parents if they have 'nested' => [ inside their block
        preg_match_all(
            '/[\'"]([a-zA-Z][a-zA-Z0-9-]*):(\w+)[\'"]\s*=>\s*\[/',
            $content,
            $allMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        // Collect all nested permission handles to identify which are children
        $nestedHandles = [];
        if (preg_match_all('/[\'"]nested[\'"]\s*=>\s*\[([^]]*(?:\[[^]]*\][^]]*)*)\]/s', $content, $nestedBlocks)) {
            foreach ($nestedBlocks[1] as $block) {
                preg_match_all('/[\'"]([a-zA-Z][a-zA-Z0-9-]*:\w+)[\'"]/', $block, $nestedInBlock);
                foreach ($nestedInBlock[1] as $handle) {
                    $nestedHandles[$handle] = true;
                }
            }
        }

        // Process each permission handle found
        foreach ($allMatches as $match) {
            $pluginHandle = $match[1][0];
            $permissionName = $match[2][0];
            $fullHandle = $pluginHandle . ':' . $permissionName;
            $offset = $match[0][1];

            // Skip if already seen or if this is a nested child (handled below)
            if (isset($seenHandles[$fullHandle]) || isset($nestedHandles[$fullHandle])) {
                continue;
            }
            $seenHandles[$fullHandle] = true;

            // Extract label
            $label = $this->extractPermissionLabel($content, $fullHandle, $permissionName);

            // Try to find group name from comment above this permission
            $groupName = $this->extractPermissionGroup($content, $offset, $permissionName);

            // Check if this permission has nested children
            $nested = $this->extractNestedPermissions($content, $fullHandle);

            // Build permission entry
            $permEntry = [
                'handle' => $fullHandle,
                'label' => $label,
            ];
            if (!empty($nested)) {
                $permEntry['nested'] = $nested;
            }

            // Find or create group
            $groupIndex = null;
            foreach ($groups as $i => $g) {
                if ($g['group'] === $groupName) {
                    $groupIndex = $i;
                    break;
                }
            }

            if ($groupIndex !== null) {
                $groups[$groupIndex]['permissions'][] = $permEntry;
            } else {
                $groups[] = [
                    'group' => $groupName,
                    'permissions' => [$permEntry],
                ];
            }
        }

        return $groups;
    }

    /**
     * Extract the label for a permission handle from source code
     */
    private function extractPermissionLabel(string $content, string $fullHandle, string $fallback): string
    {
        $labelPattern = '/[\'"]' . preg_quote($fullHandle, '/') . '[\'"]\s*=>\s*\[\s*[\'"]label[\'"]\s*=>\s*(?:Craft::t\s*\([^,]+,\s*)?[\'"]([^"\']+)[\'"]/s';

        if (preg_match($labelPattern, $content, $labelMatch)) {
            return $labelMatch[1];
        }

        return $fallback;
    }

    /**
     * Extract group name from comment above a permission or derive from handle
     */
    private function extractPermissionGroup(string $content, int $offset, string $permissionName): string
    {
        // Look for a comment in the ~200 chars before this permission
        $before = substr($content, max(0, $offset - 200), min($offset, 200));

        // Match comments like: // Backends - grouped, // Indices, // Analytics
        if (preg_match('/\/\/\s*([A-Z][A-Za-z\s\/]+?)(?:\s*-\s*\w+)?\s*$/', $before, $commentMatch)) {
            return trim($commentMatch[1]);
        }

        // Derive from permission name: manageBackends → Backends, viewAnalytics → Analytics
        $name = preg_replace('/^(manage|view|create|edit|delete|rebuild|clear|export|download|perform)/', '', $permissionName);

        if (!empty($name)) {
            // Split camelCase and capitalize
            $words = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
            return ucfirst($words);
        }

        return 'Other';
    }

    /**
     * Extract nested permissions for a parent handle
     */
    private function extractNestedPermissions(string $content, string $parentHandle): array
    {
        $nested = [];

        // Find the 'nested' => [ block that follows this parent handle
        $pattern = '/[\'"]' . preg_quote($parentHandle, '/') . '[\'"]\s*=>\s*\[.*?[\'"]nested[\'"]\s*=>\s*\[([^]]*(?:\[[^]]*\][^]]*)*)\]/s';

        if (!preg_match($pattern, $content, $nestedMatch)) {
            return [];
        }

        $nestedBlock = $nestedMatch[1];

        // Extract each nested permission
        preg_match_all('/[\'"]([a-zA-Z][a-zA-Z0-9-]*:\w+)[\'"]\s*=>\s*\[/', $nestedBlock, $nestedHandles, PREG_SET_ORDER);

        foreach ($nestedHandles as $match) {
            $handle = $match[1];
            $permName = explode(':', $handle)[1] ?? $handle;
            $label = $this->extractPermissionLabel($content, $handle, $permName);

            $nested[] = [
                'handle' => $handle,
                'label' => $label,
            ];
        }

        return $nested;
    }

    /**
     * Extract Twig globals from PluginHelper::bootstrap() usage
     *
     * Detects if plugin uses lindemannrock\base\helpers\PluginHelper::bootstrap()
     * and extracts the Twig helper variable name.
     *
     * @param string $pluginPath Plugin root path
     * @return array Array of Twig global definitions
     */
    public function extractTwigGlobals(string $pluginPath): array
    {
        $globals = [];

        // Find main plugin class
        $srcDir = $pluginPath . '/src';
        $pluginFiles = glob($srcDir . '/*.php');

        foreach ($pluginFiles as $filePath) {
            $content = file_get_contents($filePath);

            // Check if it uses PluginHelper
            if (!str_contains($content, 'PluginHelper')) {
                continue;
            }

            // Look for PluginHelper::bootstrap($this, 'helperName', ...)
            if (preg_match('/PluginHelper::bootstrap\s*\(\s*\$this\s*,\s*[\'"](\w+)[\'"]/', $content, $match)) {
                $helperName = $match[1];

                // These are the standard properties provided by PluginNameHelper
                $globals[] = [
                    'name' => $helperName,
                    'type' => 'PluginNameHelper',
                    'source' => 'lindemannrock/base',
                    'properties' => [
                        [
                            'name' => 'displayName',
                            'description' => 'Display name (singular, without "Manager")',
                            'example' => "{{ {$helperName}.displayName }}",
                        ],
                        [
                            'name' => 'pluralDisplayName',
                            'description' => 'Plural display name (without "Manager")',
                            'example' => "{{ {$helperName}.pluralDisplayName }}",
                        ],
                        [
                            'name' => 'fullName',
                            'description' => 'Full plugin name (as configured)',
                            'example' => "{{ {$helperName}.fullName }}",
                        ],
                        [
                            'name' => 'lowerDisplayName',
                            'description' => 'Lowercase display name (singular)',
                            'example' => "{{ {$helperName}.lowerDisplayName }}",
                        ],
                        [
                            'name' => 'pluralLowerDisplayName',
                            'description' => 'Lowercase plural display name',
                            'example' => "{{ {$helperName}.pluralLowerDisplayName }}",
                        ],
                    ],
                ];
            }
        }

        return $globals;
    }

    /**
     * Extract shared features usage from base plugin and logging library
     *
     * Detects usage of:
     * - lindemannrock/base helpers (PluginHelper, GeoHelper)
     * - lindemannrock/base traits (SettingsConfigTrait, SettingsDisplayNameTrait, SettingsPersistenceTrait)
     * - lindemannrock/logging-library (LoggingLibrary::configure, LoggingTrait)
     *
     * @param string $pluginPath Plugin root path
     * @return array Array of shared feature definitions
     */
    public function extractSharedFeatures(string $pluginPath): array
    {
        $features = [];
        $srcDir = $pluginPath . '/src';

        // Scan all PHP files in src directory
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $allContent = '';
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $allContent .= file_get_contents($file->getPathname()) . "\n";
            }
        }

        // === lindemannrock/base ===

        // PluginHelper::bootstrap()
        if (preg_match('/PluginHelper::bootstrap\s*\(/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'PluginHelper::bootstrap()',
                'description' => 'Initializes base module, Twig globals, and logging configuration',
                'docs' => 'Provides plugin name helpers in Twig templates (see Twig Globals section)',
            ];
        }

        // PluginHelper::applyPluginNameFromConfig()
        if (preg_match('/PluginHelper::applyPluginNameFromConfig\s*\(/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'PluginHelper::applyPluginNameFromConfig()',
                'description' => 'Overrides plugin name from config file',
                'docs' => 'Allows customizing the plugin display name via config/{plugin-handle}.php',
            ];
        }

        // PluginHelper::registerTranslations()
        if (preg_match('/PluginHelper::registerTranslations\s*\(/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'PluginHelper::registerTranslations()',
                'description' => 'Registers translation messages for the plugin',
                'docs' => 'Enables plugin-specific translations',
            ];
        }

        // SettingsConfigTrait
        if (preg_match('/use\s+.*\\\\SettingsConfigTrait\s*;/', $allContent) ||
            preg_match('/use\s+SettingsConfigTrait\s*;/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'SettingsConfigTrait',
                'description' => 'Config file override detection and log level validation',
                'docs' => 'Settings can be overridden via config/{plugin-handle}.php. Debug logging requires devMode.',
            ];
        }

        // SettingsDisplayNameTrait
        if (preg_match('/use\s+.*\\\\SettingsDisplayNameTrait\s*;/', $allContent) ||
            preg_match('/use\s+SettingsDisplayNameTrait\s*;/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'SettingsDisplayNameTrait',
                'description' => 'Standardized plugin name helper methods',
                'docs' => 'Provides getDisplayName(), getFullName(), getPluralDisplayName(), etc.',
            ];
        }

        // SettingsPersistenceTrait
        if (preg_match('/use\s+.*\\\\SettingsPersistenceTrait\s*;/', $allContent) ||
            preg_match('/use\s+SettingsPersistenceTrait\s*;/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'SettingsPersistenceTrait',
                'description' => 'Database persistence for Settings models',
                'docs' => 'Settings are stored in database with automatic type conversion for boolean, integer, float, and JSON fields.',
            ];
        }

        // GeoHelper
        if (preg_match('/GeoHelper::/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/base',
                'feature' => 'GeoHelper',
                'description' => 'Geographic utilities (country code to name conversion)',
                'docs' => 'ISO 3166-1 alpha-2 country code utilities',
            ];
        }

        // === lindemannrock/logging-library ===

        // LoggingLibrary::configure()
        if (preg_match('/LoggingLibrary::configure\s*\(/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/logging-library',
                'feature' => 'LoggingLibrary::configure()',
                'description' => 'Dedicated plugin logging configuration',
                'docs' => 'Enables dedicated log files at storage/logs/{plugin-handle}-{date}.log',
            ];
        }

        // LoggingTrait
        if (preg_match('/use\s+.*\\\\LoggingTrait\s*;/', $allContent) ||
            preg_match('/use\s+LoggingTrait\s*;/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/logging-library',
                'feature' => 'LoggingTrait',
                'description' => 'Convenient logging methods (logInfo, logWarning, logError, logDebug)',
                'docs' => 'Provides standardized logging to dedicated plugin log files',
            ];
        }

        // LoggingLibrary::addLogsNav()
        if (preg_match('/LoggingLibrary::addLogsNav\s*\(/', $allContent)) {
            $features[] = [
                'source' => 'lindemannrock/logging-library',
                'feature' => 'LoggingLibrary::addLogsNav()',
                'description' => 'Adds "Logs" subnav to plugin CP navigation',
                'docs' => 'View plugin logs directly in the Control Panel',
            ];
        }

        return $features;
    }

    /**
     * Extract plugin events (EVENT_* constants)
     *
     * Detects custom events defined in services and the event classes in src/events/
     *
     * @param string $pluginPath Plugin root path
     * @return array Array of event definitions
     */
    public function extractEvents(string $pluginPath): array
    {
        $events = [];
        $srcDir = $pluginPath . '/src';

        // Get plugin handle from composer.json
        $composerPath = $pluginPath . '/composer.json';
        $pluginHandle = 'unknown';
        $pluginNamespace = '';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $pluginHandle = $composer['extra']['handle'] ?? 'unknown';
            // Get namespace from autoload
            $autoload = $composer['autoload']['psr-4'] ?? [];
            $pluginNamespace = array_key_first($autoload) ?? '';
            $pluginNamespace = rtrim($pluginNamespace, '\\');
        }

        // Find event classes in src/events/
        $eventClasses = [];
        $eventsDir = $srcDir . '/events';
        if (is_dir($eventsDir)) {
            $eventFiles = glob($eventsDir . '/*Event.php');
            foreach ($eventFiles as $file) {
                $content = file_get_contents($file);
                if (preg_match('/class\s+(\w+Event)/', $content, $match)) {
                    $className = $match[1];
                    $eventClasses[$className] = [
                        'class' => $className,
                        'namespace' => $pluginNamespace . '\\events\\' . $className,
                        'file' => basename($file),
                    ];

                    // Extract properties from event class
                    $properties = [];
                    preg_match_all('/public\s+(?:(\??\w+)\s+)?\$(\w+)/', $content, $propMatches, PREG_SET_ORDER);
                    foreach ($propMatches as $prop) {
                        if ($prop[2] !== 'sender') { // Skip inherited properties
                            $properties[] = [
                                'name' => $prop[2],
                                'type' => $prop[1] ?: 'mixed',
                            ];
                        }
                    }
                    $eventClasses[$className]['properties'] = $properties;
                }
            }
        }

        // Find EVENT_* constants in services
        $servicesDir = $srcDir . '/services';
        if (is_dir($servicesDir)) {
            $serviceFiles = glob($servicesDir . '/*Service.php');
            foreach ($serviceFiles as $file) {
                $content = file_get_contents($file);
                $serviceName = '';
                if (preg_match('/class\s+(\w+Service)/', $content, $classMatch)) {
                    $serviceName = $classMatch[1];
                }

                // Find EVENT_* constants with their docblocks
                $pattern = '/\/\*\*\s*\n(.*?)\*\/\s*\n\s*public\s+const\s+(EVENT_\w+)\s*=\s*[\'"]([^"\']+)[\'"]/s';
                preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $docblock = $match[1];
                    $constName = $match[2];
                    $eventName = $match[3];

                    // Extract description from docblock
                    $description = '';
                    if (preg_match('/@event\s+(\w+)/', $docblock, $eventMatch)) {
                        $eventClass = $eventMatch[1];
                    } else {
                        $eventClass = 'Event';
                    }

                    // Get first line of docblock as description (skip file headers)
                    $skipPatterns = ['plugin for Craft CMS', '@link', '@copyright', '@author', '@package', '@since'];
                    $lines = explode("\n", $docblock);
                    foreach ($lines as $line) {
                        $line = trim(preg_replace('/^\*\s?/', '', $line));
                        if (empty($line) || str_starts_with($line, '@')) {
                            continue;
                        }
                        // Check skip patterns
                        $skip = false;
                        foreach ($skipPatterns as $pattern) {
                            if (str_contains($line, $pattern)) {
                                $skip = true;
                                break;
                            }
                        }
                        if (!$skip) {
                            $description = $line;
                            break;
                        }
                    }

                    $events[] = [
                        'constant' => $constName,
                        'name' => $eventName,
                        'service' => $serviceName,
                        'serviceClass' => $pluginNamespace . '\\services\\' . $serviceName,
                        'eventClass' => $eventClass,
                        'description' => $description,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * Extract integrations from src/integrations/ folder
     *
     * @param string $pluginPath Plugin root path
     * @return array Array of integration definitions
     */
    public function extractIntegrations(string $pluginPath): array
    {
        $integrations = [];
        $integrationsDir = $pluginPath . '/src/integrations';

        if (!is_dir($integrationsDir)) {
            return $integrations;
        }

        $files = glob($integrationsDir . '/*Integration.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileName = basename($file);

            // Get class name
            if (!preg_match('/class\s+(\w+Integration)/', $content, $classMatch)) {
                continue;
            }

            $className = $classMatch[1];

            // Skip base/abstract classes
            if (str_contains($content, 'abstract class') || $className === 'BaseIntegration') {
                continue;
            }

            // Extract description from class docblock
            $description = '';
            // Skip patterns for file headers
            $skipPatterns = ['plugin for Craft CMS', '@link', '@copyright', '@author', '@package', '@since'];
            if (preg_match('/\/\*\*\s*\n(.*?)\*\/\s*\n\s*class/s', $content, $docMatch)) {
                $docblock = $docMatch[1];
                $lines = explode("\n", $docblock);
                foreach ($lines as $line) {
                    $line = trim(preg_replace('/^\*\s?/', '', $line));
                    if (empty($line) || str_starts_with($line, '@') || str_contains($line, 'Integration')) {
                        continue;
                    }
                    // Check skip patterns
                    $skip = false;
                    foreach ($skipPatterns as $pattern) {
                        if (str_contains($line, $pattern)) {
                            $skip = true;
                            break;
                        }
                    }
                    if (!$skip) {
                        $description = $line;
                        break;
                    }
                }
            }

            // Try to detect what plugin this integrates with
            $integratesWith = '';
            if (preg_match('/RedirectManager/i', $className)) {
                $integratesWith = 'lindemannrock/redirect-manager';
            } elseif (preg_match('/Seomatic/i', $className)) {
                $integratesWith = 'nystudio107/seomatic';
            } elseif (preg_match('/Formie/i', $className)) {
                $integratesWith = 'verbb/formie';
            }

            $integrations[] = [
                'class' => $className,
                'file' => $fileName,
                'description' => $description,
                'integratesWith' => $integratesWith,
            ];
        }

        return $integrations;
    }

    /**
     * Extract GraphQL support information
     *
     * @param string $pluginPath Plugin root path
     * @return array Array of GraphQL definitions
     */
    public function extractGraphQL(string $pluginPath): array
    {
        $graphql = [
            'enabled' => false,
            'types' => [],
            'queries' => [],
            'mutations' => [],
        ];

        $gqlDir = $pluginPath . '/src/gql';
        $srcDir = $pluginPath . '/src';

        // Check if gql directory exists and has files
        if (is_dir($gqlDir)) {
            $gqlFiles = glob($gqlDir . '/*.php');
            if (!empty($gqlFiles)) {
                $graphql['enabled'] = true;
            }

            // Find types
            $typesDir = $gqlDir . '/types';
            if (is_dir($typesDir)) {
                $typeFiles = glob($typesDir . '/*.php');
                foreach ($typeFiles as $file) {
                    $content = file_get_contents($file);
                    if (preg_match('/class\s+(\w+)/', $content, $match)) {
                        $graphql['types'][] = $match[1];
                    }
                }
            }

            // Find queries
            $queriesDir = $gqlDir . '/queries';
            if (is_dir($queriesDir)) {
                $queryFiles = glob($queriesDir . '/*.php');
                foreach ($queryFiles as $file) {
                    $content = file_get_contents($file);
                    if (preg_match('/class\s+(\w+)/', $content, $match)) {
                        $graphql['queries'][] = $match[1];
                    }
                }
            }

            // Find mutations
            $mutationsDir = $gqlDir . '/mutations';
            if (is_dir($mutationsDir)) {
                $mutationFiles = glob($mutationsDir . '/*.php');
                foreach ($mutationFiles as $file) {
                    $content = file_get_contents($file);
                    if (preg_match('/class\s+(\w+)/', $content, $match)) {
                        $graphql['mutations'][] = $match[1];
                    }
                }
            }
        }

        // Also check main plugin class for GQL registration
        $pluginFiles = glob($srcDir . '/*.php');
        foreach ($pluginFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'EVENT_REGISTER_GQL') ||
                str_contains($content, 'registerGqlTypes') ||
                str_contains($content, 'registerGqlQueries')) {
                $graphql['enabled'] = true;
                break;
            }
        }

        return $graphql;
    }

    /**
     * Extract controller options (public properties that are options)
     */
    private function extractControllerOptions(string $content): array
    {
        $options = [];

        // Find options() method to know which properties are options
        if (preg_match('/function\s+options\s*\([^)]*\)[^{]*\{([^}]+)\}/s', $content, $optMatch)) {
            preg_match_all('/\$options\[\]\s*=\s*[\'"](\w+)[\'"]/', $optMatch[1], $optNames);
            $optionNames = $optNames[1];

            // Now find the properties with their docblocks
            foreach ($optionNames as $optName) {
                // Match: /** @var type description */ public type $name
                // Use [^\n]* to only capture to end of line
                $pattern = '/\/\*\*\s*\n\s*\*\s*@var\s+(\S+)\s+([^\n]*)\n\s*\*\/\s*\n\s*public\s+(?:\??\w+\s+)?\$' . $optName . '/';
                if (preg_match($pattern, $content, $propMatch)) {
                    $options[] = [
                        'name' => $optName,
                        'type' => $propMatch[1],
                        'description' => trim($propMatch[2]),
                    ];
                }
            }
        }

        return $options;
    }

    /**
     * Extract description from docblock
     */
    private function extractDocblockDescription(string $docblock): string
    {
        $lines = explode("\n", $docblock);
        $description = [];
        $skipPatterns = [
            '/plugin for Craft CMS/i',
            '/@link/',
            '/@copyright/',
            '/@author/',
            '/@package/',
            '/@since/',
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\*\s?/', '', $line);

            // Stop at first @tag (except the ones we skip)
            if (str_starts_with($line, '@')) {
                break;
            }

            // Skip empty lines and common header patterns
            if (empty($line)) {
                continue;
            }

            // Skip lines that look like file headers
            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $skip = true;
                    break;
                }
            }

            if (!$skip) {
                $description[] = $line;
            }
        }

        return implode(' ', $description);
    }

    /**
     * Extract usage example from docblock
     */
    private function extractDocblockUsage(string $docblock): ?string
    {
        if (preg_match('/Usage:\s*(.+?)(?=\n\s*\*\s*@|\n\s*\*\s*\n|\z)/s', $docblock, $match)) {
            $usage = trim($match[1]);
            $usage = preg_replace('/^\*\s*/m', '', $usage);
            return trim($usage);
        }
        return null;
    }

    /**
     * Extract @param tags from docblock
     */
    private function extractDocblockParams(string $docblock): array
    {
        $params = [];

        // Clean up docblock - remove leading * and whitespace from each line
        $lines = explode("\n", $docblock);
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = preg_replace('/^\s*\*\s?/', '', $line);
            $cleanLines[] = $line;
        }
        $cleanDocblock = implode("\n", $cleanLines);

        // Match @param type $name description (description is optional and ends at newline)
        // Use [ \t]+ for spaces (not \s+ which includes newlines)
        preg_match_all('/@param\s+(\S+)\s+\$(\w+)(?:[ \t]+(.+))?$/m', $cleanDocblock, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $description = isset($match[3]) ? trim($match[3]) : '';

            $params[] = [
                'type' => $match[1],
                'name' => $match[2],
                'description' => $description,
            ];
        }

        return $params;
    }

    /**
     * Extract @return type from docblock
     */
    private function extractDocblockReturn(string $docblock): ?string
    {
        if (preg_match('/@return\s+(\S+)/', $docblock, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Parse method parameters from signature
     */
    private function parseMethodParams(string $params): array
    {
        $result = [];

        if (empty(trim($params))) {
            return $result;
        }

        $parts = explode(',', $params);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Match: ?Type $name = default
            if (preg_match('/^(\??\w+)?\s*\$(\w+)(?:\s*=\s*(.+))?$/', $part, $match)) {
                $result[] = [
                    'type' => !empty($match[1]) ? $match[1] : 'mixed',
                    'name' => $match[2],
                    'default' => isset($match[3]) ? trim($match[3]) : null,
                ];
            }
        }

        return $result;
    }
}
