<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\twigextensions;

use lindemannrock\docsmanager\DocsManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Plugin Name Twig Extension
 *
 * @since 5.0.0
 */
class PluginNameExtension extends AbstractExtension implements GlobalsInterface
{
    public function getName(): string
    {
        return 'Docs Manager - Plugin Name Helper';
    }

    public function getGlobals(): array
    {
        return ['docsManagerHelper' => new PluginNameHelper()];
    }
}

/**
 * Plugin Name Helper
 *
 * Provides plugin name accessors for Twig templates.
 *
 * @since 5.0.0
 */
class PluginNameHelper
{
    public function getDisplayName(): string
    {
        return DocsManager::$plugin->getSettings()->getDisplayName();
    }

    public function getPluralDisplayName(): string
    {
        return DocsManager::$plugin->getSettings()->getPluralDisplayName();
    }

    public function getFullName(): string
    {
        return DocsManager::$plugin->getSettings()->getFullName();
    }

    public function getLowerDisplayName(): string
    {
        return DocsManager::$plugin->getSettings()->getLowerDisplayName();
    }

    public function getPluralLowerDisplayName(): string
    {
        return DocsManager::$plugin->getSettings()->getPluralLowerDisplayName();
    }

    public function __get(string $name): ?string
    {
        $method = 'get' . ucfirst($name);
        return method_exists($this, $method) ? $this->$method() : null;
    }
}
