<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\console\controllers;

use Craft;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\records\SourceRecord;

/**
 * Shared plugin path resolution for CLI controllers.
 *
 * Accepts either a DB handle (e.g., `lindemannrock-base`) or a folder name
 * (e.g., `base`) and resolves to the plugin's filesystem path + canonical handle.
 *
 * @since 5.0.0
 */
trait PluginPathTrait
{
    /**
     * Resolve a CLI argument to a plugin path and handle.
     *
     * Resolution order:
     * 1. Look up SourceRecord by handle — use localPath or localPluginBasePath + handle
     * 2. Try localPluginBasePath + input as folder name — read composer.json for real handle
     * 3. Return null if neither resolves
     *
     * @return array{path: string, handle: string}|null
     */
    protected function resolvePluginPath(string $input): ?array
    {
        $settings = DocsManager::getInstance()->getSettings();
        $basePath = Craft::getAlias($settings->localPluginBasePath);

        // 1. Try as DB handle
        $record = SourceRecord::findOne(['handle' => $input]);
        if ($record) {
            $path = $record->localPath
                ? Craft::getAlias($record->localPath)
                : $basePath . '/' . $record->handle;

            if (is_dir($path)) {
                return ['path' => $path, 'handle' => $record->handle];
            }
        }

        // 2. Try as folder name
        $folderPath = $basePath . '/' . $input;
        if (is_dir($folderPath)) {
            $handle = $this->readHandleFromComposer($folderPath) ?? $input;
            return ['path' => $folderPath, 'handle' => $handle];
        }

        return null;
    }

    /**
     * Read the plugin handle from composer.json extra.handle
     */
    private function readHandleFromComposer(string $pluginPath): ?string
    {
        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) {
            return null;
        }

        $data = json_decode(file_get_contents($composerPath), true);
        return $data['extra']['handle'] ?? null;
    }
}
