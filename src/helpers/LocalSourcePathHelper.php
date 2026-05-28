<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\helpers;

use lindemannrock\base\helpers\StoragePathHelper;

/**
 * Resolves local source paths from settings and source records.
 *
 * @since 5.1.0
 */
class LocalSourcePathHelper
{
    public static function resolve(string $path): string
    {
        return StoragePathHelper::resolve($path);
    }

    public static function join(string $basePath, string $relativePath): string
    {
        return rtrim(self::resolve($basePath), '/\\') . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    }
}
