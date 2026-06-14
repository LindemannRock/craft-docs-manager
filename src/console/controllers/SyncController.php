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
 * Sync documentation from plugin repositories
 *
 * @since 5.0.0
 */
class SyncController extends Controller
{
    use PluginPathTrait;

    /**
     * @var string|null Plugin handle to sync (if not provided, syncs all)
     */
    public ?string $plugin = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'plugin';
        return $options;
    }

    /**
     * Sync documentation for a specific plugin or all plugins
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("Docs Manager - Documentation Sync\n\n", Console::FG_CYAN);

        if ($this->plugin) {
            return $this->syncPlugin($this->plugin);
        }

        // Sync all plugins
        $this->stdout("Syncing all plugins...\n\n", Console::FG_YELLOW);

        $results = DocsManager::getInstance()->sync->syncAllPlugins();
        $hasErrors = false;

        foreach ($results as $handle => $result) {
            $this->displayResult($handle, $result);
            if (!$result['success']) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->stderr("\nSync completed with errors.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nSync complete!\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Sync a specific plugin
     *
     * Usage: php craft docs-manager/sync translation-manager
     *
     * @param string $handle Plugin handle
     * @return int Exit code
     */
    public function actionPlugin(string $handle): int
    {
        return $this->syncPlugin($handle);
    }

    /**
     * Check version for a plugin
     *
     * Usage: php craft docs-manager/sync/version translation-manager
     *
     * @param string $handle Plugin handle
     * @return int Exit code
     */
    public function actionVersion(string $handle): int
    {
        $resolved = $this->resolvePluginPath($handle);
        if (!$resolved) {
            $this->stderr("Error: Could not resolve plugin \"{$handle}\" — not a known handle or folder.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Checking version for: {$resolved['handle']}\n\n", Console::FG_CYAN);

        $versionData = DocsManager::getInstance()->versionDetector->getPluginVersion($resolved['handle'], $resolved['path']);

        if ($versionData) {
            $this->stdout("✓ Version: {$versionData['version']}\n", Console::FG_GREEN);
            $this->stdout("  Source: {$versionData['source']}\n");
            if ($versionData['releaseDate']) {
                $this->stdout("  Released: {$versionData['releaseDate']}\n");
            }
        } else {
            $this->stderr("✗ Version not found\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Test parser with a markdown file
     *
     * Usage: php craft docs-manager/sync/test-parser /path/to/file.md
     *
     * @param string $filePath Path to markdown file
     * @return int Exit code
     */
    public function actionTestParser(string $filePath): int
    {
        $this->stdout("Testing parser with: {$filePath}\n\n", Console::FG_CYAN);

        if (!file_exists($filePath)) {
            $this->stderr("File not found: {$filePath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $markdown = file_get_contents($filePath);
        $parsed = DocsManager::getInstance()->parser->parseMarkdown($markdown);

        $this->stdout("Frontmatter:\n", Console::FG_YELLOW);
        print_r($parsed['frontmatter']);

        $this->stdout("\nHeadings:\n", Console::FG_YELLOW);
        foreach ($parsed['headings'] as $heading) {
            $indent = str_repeat('  ', $heading['level'] - 2);
            $this->stdout("{$indent}H{$heading['level']}: {$heading['text']} (#{$heading['anchor']})\n");
        }

        $this->stdout("\nHTML Preview (first 500 chars):\n", Console::FG_YELLOW);
        $this->stdout(substr($parsed['html'], 0, 500) . "...\n");

        return ExitCode::OK;
    }

    /**
     * Sync a specific plugin and display results
     */
    protected function syncPlugin(string $input): int
    {
        $resolved = $this->resolvePluginPath($input);
        if (!$resolved) {
            $this->stderr("Error: Could not resolve plugin \"{$input}\" — not a known handle or folder.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $handle = $resolved['handle'];
        $this->stdout("Syncing: {$handle}\n\n", Console::FG_CYAN);

        $result = DocsManager::getInstance()->sync->syncPlugin($handle);

        $this->displayResult($handle, $result);

        if ($result['success']) {
            return ExitCode::OK;
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Display sync result
     */
    protected function displayResult(string $handle, array $result): void
    {
        if ($result['success']) {
            $this->stdout("✓ {$handle}\n", Console::FG_GREEN);
            $this->stdout("  Pages synced: {$result['pages']}\n");
            if ($result['version']) {
                $this->stdout("  Version: {$result['version']}\n");
            }
            if (!empty($result['versions']) && is_array($result['versions'])) {
                foreach ($result['versions'] as $versionResult) {
                    if (!is_array($versionResult)) {
                        continue;
                    }
                    $label = $versionResult['label'] ?? 'Unknown';
                    $ref = $versionResult['ref'] ?? 'unknown';
                    $pages = $versionResult['pages'] ?? 0;
                    $this->stdout("  - {$label} ({$ref}): {$pages} pages\n");
                }
            }
        } else {
            $this->stderr("✗ {$handle}\n", Console::FG_RED);
            foreach ($result['errors'] as $error) {
                $this->stderr("  - {$error}\n", Console::FG_RED);
            }
        }

        $this->stdout("\n");
    }
}
