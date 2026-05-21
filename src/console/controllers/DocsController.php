<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use lindemannrock\docsmanager\DocsManager;
use yii\console\ExitCode;

/**
 * Documentation Generation Controller
 *
 * Generates docs skeleton from plugin source code and migrates READMEs
 *
 * @since 5.0.0
 */
class DocsController extends Controller
{
    use PluginPathTrait;

    /**
     * @var bool Dry run mode - preview without writing
     */
    public bool $dryRun = false;

    /**
     * @var bool Force overwrite existing index.json
     */
    public bool $force = false;

    /**
     * @var bool Verbose output
     */
    public bool $verbose = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'dryRun';
        $options[] = 'force';
        $options[] = 'verbose';
        return $options;
    }

    /**
     * Generate documentation from code (NEW - extracts from PHP source)
     *
     * Usage: php craft docs-manager/docs/create translation-manager
     *
     * @param string|null $plugin Plugin handle (auto-detect if in plugin directory)
     * @return int Exit code
     */
    public function actionCreate(?string $plugin = null): int
    {
        $this->stdout("Docs Manager Generator v2\n", Console::FG_CYAN);
        $this->stdout("Extracts documentation from code\n\n", Console::FG_GREY);

        // 1. Determine plugin path
        if (!$plugin) {
            // Try to detect from current directory
            $cwd = getcwd();
            if ($cwd !== false && str_contains($cwd, '/plugins/') && file_exists($cwd . '/composer.json')) {
                $plugin = basename($cwd);
            } else {
                $this->stderr("Error: Could not determine plugin path.\n", Console::FG_RED);
                $this->stderr("Usage: php craft docs-manager/docs/create <plugin-handle>\n", Console::FG_YELLOW);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $resolved = $this->resolvePluginPath($plugin);
        if (!$resolved) {
            $this->stderr("Error: Could not resolve plugin \"{$plugin}\" — not a known handle or folder.\n", Console::FG_RED);
            $this->stderr("Usage: php craft docs-manager/docs/create <plugin-handle>\n", Console::FG_YELLOW);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pluginPath = $resolved['path'];
        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) {
            $this->stderr("Error: composer.json not found at {$composerPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $composerData = json_decode(file_get_contents($composerPath), true);
        $pluginName = $composerData['extra']['name'] ?? 'Unknown Plugin';
        $pluginHandle = $composerData['extra']['handle'] ?? $resolved['handle'];

        $this->stdout("Plugin: {$pluginName} ({$pluginHandle})\n", Console::FG_GREEN);
        $this->stdout("Path: {$pluginPath}\n\n");

        // 2. Preview extraction (if verbose)
        if ($this->verbose) {
            $this->stdout("Extracting from code...\n", Console::FG_YELLOW);
            $extracted = DocsManager::getInstance()->codeExtractor->extractAll($pluginPath);

            $this->stdout("  Settings: " . count($extracted['settings']) . " properties\n");
            $this->stdout("  Variables: " . count($extracted['variables']) . " methods\n");
            $this->stdout("  Commands: " . count($extracted['commands']) . " commands\n");
            $this->stdout("  Permissions: " . count($extracted['permissions']) . " permissions\n");
            $this->stdout("  Events: " . count($extracted['events']) . " events\n");
            $this->stdout("  Twig Globals: " . count($extracted['twigGlobals']) . " globals\n");
            $this->stdout("  Integrations: " . count($extracted['integrations']) . " integrations\n");
            $this->stdout("  GraphQL: " . ($extracted['graphql']['enabled'] ? 'enabled' : 'disabled') . "\n");
            $this->stdout("  Shared Features: " . count($extracted['sharedFeatures']) . " features\n\n");
        }

        // 3. Dry run check
        if ($this->dryRun) {
            $this->stdout("Dry run mode - showing what would be generated:\n\n", Console::FG_YELLOW);

            $extracted = DocsManager::getInstance()->codeExtractor->extractAll($pluginPath);

            if (!empty($extracted['settings'])) {
                $this->stdout("Would generate: docs/get-started/configuration.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['variables'])) {
                $this->stdout("Would generate: docs/developers/template-variables.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['commands'])) {
                $this->stdout("Would generate: docs/developers/console-commands.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['permissions'])) {
                $this->stdout("Would generate: docs/developers/permissions.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['twigGlobals'])) {
                $this->stdout("Would generate: docs/developers/twig-globals.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['sharedFeatures'])) {
                $this->stdout("Would generate: docs/developers/shared-features.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['events'])) {
                $this->stdout("Would generate: docs/developers/events.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['integrations'])) {
                $this->stdout("Would generate: docs/developers/integrations.md\n", Console::FG_GREY);
            }
            if (!empty($extracted['graphql']) && $extracted['graphql']['enabled']) {
                $this->stdout("Would generate: docs/developers/graphql.md\n", Console::FG_GREY);
            }
            $this->stdout("Would generate: docs/get-started/installation.md (boilerplate)\n", Console::FG_GREY);
            $this->stdout("Would generate: docs/get-started/requirements.md (boilerplate)\n", Console::FG_GREY);
            $this->stdout("Would generate: docs/.sidebar.json\n", Console::FG_GREY);
            $this->stdout("Would generate: docs/plugin.json\n", Console::FG_GREY);
            $this->stdout("Would generate: docs/.docs-meta.json\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        // 4. Generate docs
        $this->stdout("Generating documentation...\n\n", Console::FG_CYAN);

        $results = DocsManager::getInstance()->docsGenerator->generate($pluginPath, $this->force);

        // 5. Display results
        if (!empty($results['generated'])) {
            $this->stdout("Generated:\n", Console::FG_GREEN);
            foreach ($results['generated'] as $file) {
                $this->stdout("  ✓ {$file}\n", Console::FG_GREEN);
            }
        }

        if (!empty($results['skipped'])) {
            $this->stdout("\nSkipped:\n", Console::FG_YELLOW);
            foreach ($results['skipped'] as $file) {
                $this->stdout("  - {$file}\n", Console::FG_YELLOW);
            }
        }

        if (!empty($results['errors'])) {
            $this->stdout("\nErrors:\n", Console::FG_RED);
            foreach ($results['errors'] as $error) {
                $this->stderr("  ✗ {$error}\n", Console::FG_RED);
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n✓ Documentation generated successfully!\n", Console::FG_GREEN);
        $this->stdout("  Location: {$pluginPath}/docs/\n", Console::FG_GREY);
        $this->stdout("  Meta: {$pluginPath}/docs/.docs-meta.json\n\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Migrate README.md sections to structured documentation files
     *
     * Usage: php craft docs-manager/docs/migrate translation-manager
     *
     * This command:
     * - Parses README.md and extracts sections (## and ### headings)
     * - Consolidates existing uppercase files (LOGGING.md, CONFIGURATION.md)
     * - Creates structured doc files (feature-tour/, get-started/, developers/, etc.)
     *
     * @param string|null $plugin Plugin handle (auto-detect if in plugin directory)
     * @return int Exit code
     */
    public function actionMigrate(?string $plugin = null): int
    {
        $this->stdout("README Migration Tool\n", Console::FG_CYAN);
        $this->stdout("Extracts README sections into structured docs\n\n", Console::FG_GREY);

        // 1. Determine plugin path
        if (!$plugin) {
            $cwd = getcwd();
            if ($cwd !== false && str_contains($cwd, '/plugins/') && file_exists($cwd . '/composer.json')) {
                $plugin = basename($cwd);
            } else {
                $this->stderr("Error: Could not determine plugin path.\n", Console::FG_RED);
                $this->stderr("Usage: php craft docs-manager/docs/migrate <plugin-handle>\n", Console::FG_YELLOW);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $resolved = $this->resolvePluginPath($plugin);
        if (!$resolved) {
            $this->stderr("Error: Could not resolve plugin \"{$plugin}\" — not a known handle or folder.\n", Console::FG_RED);
            $this->stderr("Usage: php craft docs-manager/docs/migrate <plugin-handle>\n", Console::FG_YELLOW);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pluginPath = $resolved['path'];
        $readmePath = $pluginPath . '/README.md';
        if (!file_exists($readmePath)) {
            $this->stderr("Error: README.md not found at {$readmePath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) {
            $this->stderr("Error: composer.json not found at {$composerPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $composerData = json_decode(file_get_contents($composerPath), true);
        $pluginName = $composerData['extra']['name'] ?? 'Unknown Plugin';

        $this->stdout("Plugin: {$pluginName}\n", Console::FG_GREEN);
        $this->stdout("Path: {$pluginPath}\n\n");

        // 2. Preview extraction (if verbose)
        if ($this->verbose) {
            $this->stdout("Parsing README.md...\n", Console::FG_YELLOW);
            $sections = DocsManager::getInstance()->readmeParser->parseReadme($readmePath);

            $this->stdout("Found " . count($sections) . " sections:\n");
            foreach ($sections as $section) {
                $mapped = $section['mappedPath'] ?? 'unmapped';
                $contentLen = strlen($section['content']);
                $this->stdout("  {$section['heading']} → {$mapped} ({$contentLen} chars)\n");
            }
            $this->stdout("\n");
        }

        // 3. Run migration
        $results = DocsManager::getInstance()->readmeParser->migrate($pluginPath, $this->dryRun);

        // 4. Display results
        if ($this->dryRun) {
            $this->stdout("Dry run mode - showing what would happen:\n\n", Console::FG_YELLOW);
        }

        if (!empty($results['consolidated'])) {
            $this->stdout("Consolidated files:\n", Console::FG_CYAN);
            foreach ($results['consolidated'] as $file) {
                $this->stdout("  → {$file}\n", Console::FG_CYAN);
            }
            $this->stdout("\n");
        }

        if (!empty($results['created'])) {
            $this->stdout("Created:\n", Console::FG_GREEN);
            foreach ($results['created'] as $file) {
                $this->stdout("  ✓ {$file}\n", Console::FG_GREEN);
            }
            $this->stdout("\n");
        }

        if (!empty($results['skipped'])) {
            $this->stdout("Skipped:\n", Console::FG_YELLOW);
            foreach ($results['skipped'] as $file) {
                $this->stdout("  - {$file}\n", Console::FG_YELLOW);
            }
            $this->stdout("\n");
        }

        if (!empty($results['errors'])) {
            $this->stdout("Errors:\n", Console::FG_RED);
            foreach ($results['errors'] as $error) {
                $this->stderr("  ✗ {$error}\n", Console::FG_RED);
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->dryRun) {
            $this->stdout("✓ Migration complete!\n", Console::FG_GREEN);
            $this->stdout("  Location: {$pluginPath}/docs/\n\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }
}
