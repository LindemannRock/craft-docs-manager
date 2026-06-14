<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\variables;

use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\helpers\LocalSourcePathHelper;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\docsmanager\records\SourceVersionRecord;

/**
 * Docs Manager Variable
 *
 * Makes plugin data available in Twig templates via craft.docsManager
 *
 * @since 5.0.0
 */
class DocsManagerVariable
{
    /**
     * Get all enabled sources, optionally filtered by kind
     *
     * Usage:
     *   {% set all = craft.docsManager.getSources() %}
     *   {% set plugins = craft.docsManager.getSources('plugin') %}
     *   {% set themes = craft.docsManager.getSources('theme') %}
     *
     * @param string|null $kind Filter by kind ('plugin', 'theme'), or null for all
     * @return array
     */
    public function getSources(?string $kind = null): array
    {
        $query = SourceRecord::find()
            ->where(['enabled' => true]);

        if ($kind !== null) {
            $query->andWhere(['kind' => $kind]);
        }

        return $query
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();
    }

    /**
     * Get all enabled plugin sources
     *
     * Usage: {% set plugins = craft.docsManager.getPlugins() %}
     *
     * @return array
     */
    public function getPlugins(): array
    {
        return $this->getSources('plugin');
    }

    /**
     * Get all enabled theme sources
     *
     * Usage: {% set themes = craft.docsManager.getThemes() %}
     *
     * @return array
     */
    public function getThemes(): array
    {
        return $this->getSources('theme');
    }

    /**
     * Get source by handle
     *
     * Usage: {% set source = craft.docsManager.getSource('translation-manager') %}
     *
     * @param string $handle
     * @return array|null
     */
    public function getSource(string $handle): ?array
    {
        return SourceRecord::find()
            ->where(['handle' => $handle])
            ->asArray()
            ->one();
    }

    /**
     * Get plugin source by handle
     *
     * Usage: {% set plugin = craft.docsManager.getPlugin('search-manager') %}
     *
     * @param string $handle
     * @return array|null
     */
    public function getPlugin(string $handle): ?array
    {
        return SourceRecord::find()
            ->where(['handle' => $handle, 'kind' => 'plugin'])
            ->asArray()
            ->one();
    }

    /**
     * Get theme source by handle
     *
     * Usage: {% set theme = craft.docsManager.getTheme('medical') %}
     *
     * @param string $handle
     * @return array|null
     */
    public function getTheme(string $handle): ?array
    {
        return SourceRecord::find()
            ->where(['handle' => $handle, 'kind' => 'theme'])
            ->asArray()
            ->one();
    }

    /**
     * Get documentation pages for a source
     *
     * Usage: {% set pages = craft.docsManager.getPages('translation-manager') %}
     *
     * @param string $handle Source handle
     * @param string|null $category Optional category filter
     * @return SourceDoc[]
     */
    public function getPages(string $handle, ?string $category = null, ?string $version = null): array
    {
        $source = $this->getSource($handle);
        if (!$source) {
            return [];
        }

        $version = $this->normalizeVersionSlug($version);
        $query = SourceDoc::find()
            ->sourceId($source['id'])
            ->version($version)
            ->status(null);

        if ($category) {
            $query->category($category);
        }

        $query->orderBy(['docsmanager_pages.order' => SORT_ASC]);

        return $query->all();
    }

    /**
     * Get custom pages for a source
     *
     * Usage: {% set pages = craft.docsManager.getCustomPages('translation-manager') %}
     *
     * @param string $handle Source handle
     * @return PluginPage[]
     */
    public function getCustomPages(string $handle): array
    {
        $source = $this->getSource($handle);
        if (!$source) {
            return [];
        }

        return PluginPage::find()
            ->sourceId($source['id'])
            ->status(null)
            ->orderBy(['docsmanager_custom_pages.order' => SORT_ASC])
            ->all();
    }

    /**
     * Get single documentation page
     *
     * Usage: {% set page = craft.docsManager.getPage('translation-manager', 'installation') %}
     *
     * @param string $handle Source handle
     * @param string $slug Page slug
     * @return SourceDoc|null
     */
    public function getPage(string $handle, string $slug, ?string $version = null): ?SourceDoc
    {
        $source = $this->getSource($handle);
        if (!$source) {
            return null;
        }

        return SourceDoc::find()
            ->sourceId($source['id'])
            ->version($this->normalizeVersionSlug($version))
            ->slug($slug)
            ->status(null)
            ->one();
    }

    /**
     * Get navigation structure from .sidebar.json
     *
     * Usage: {% set nav = craft.docsManager.getNavigation('translation-manager') %}
     *
     * @param string $handle Source handle
     * @return array|null
     */
    public function getNavigation(string $handle, ?string $version = null): ?array
    {
        $source = $this->getSource($handle);
        $version = $this->normalizeVersionSlug($version);

        if ($version !== '') {
            return $this->buildNavigationFromPages($handle, $version);
        }

        if ($source && !empty($source['localPath'])) {
            $basePath = LocalSourcePathHelper::resolve($source['localPath']);
        } else {
            $basePath = LocalSourcePathHelper::join('@root/plugins', $handle);
        }

        $sidebarPath = $basePath . '/docs/.sidebar.json';

        if (!file_exists($sidebarPath)) {
            return $this->buildNavigationFromPages($handle, $version);
        }

        $content = file_get_contents($sidebarPath);
        $sidebarData = json_decode($content, true);

        if (!$sidebarData) {
            return $this->buildNavigationFromPages($handle, $version);
        }

        // Get pages from database to enrich with metadata
        $dbPages = [];
        if ($source) {
            $pages = SourceDoc::find()
                ->sourceId($source['id'])
                ->status(null)
                ->all();
            foreach ($pages as $page) {
                $dbPages[$page->slug] = $page;
            }
        }

        // Transform sidebar format to navigation format
        $navigation = [];
        $order = 0;

        foreach ($sidebarData as $section) {
            $order++;
            $title = $section['title'] ?? 'Unknown';
            $categoryKey = $this->titleToSlug($title);
            $children = $section['children'] ?? [];

            $pages = [];
            foreach ($children as $child) {
                $slug = SlugHandleHelper::normalizePathSlug((string) $child, '');
                if ($slug === '') {
                    continue;
                }

                $dbPage = $dbPages[$slug] ?? null;
                $pages[] = [
                    'slug' => $slug,
                    'title' => $dbPage?->title ?? $this->slugToTitle(basename($slug)),
                    'description' => $dbPage?->description ?? '',
                ];
            }

            $navigation[$categoryKey] = [
                'label' => $title,
                'order' => $order,
                'collapsable' => $section['collapsable'] ?? true,
                'open' => $section['open'] ?? true,
                'pages' => $pages,
            ];
        }

        return $navigation;
    }

    /**
     * Get configured docs versions for a source.
     *
     * @param string $handle Source handle
     * @return SourceVersionRecord[]
     * @since 5.2.0
     */
    public function getVersions(string $handle): array
    {
        $source = $this->getSource($handle);
        if (!$source) {
            return [];
        }

        /** @var SourceVersionRecord[] $versions */
        $versions = SourceVersionRecord::find()
            ->where(['sourceId' => $source['id']])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $versions;
    }

    /**
     * Get the configured docs version for the current URL context.
     *
     * @since 5.2.0
     */
    public function getVersion(string $handle, ?string $version = null): ?SourceVersionRecord
    {
        $source = $this->getSource($handle);
        if (!$source) {
            return null;
        }

        $version = $this->normalizeVersionSlug($version);
        $criteria = ['sourceId' => $source['id']];

        if ($version === '') {
            $criteria['isDefault'] = true;
        } else {
            $criteria['slug'] = $version;
        }

        return SourceVersionRecord::findOne($criteria);
    }

    /**
     * Build a docs URL for a source/version/page tuple.
     *
     * @since 5.2.0
     */
    public function getDocUrl(string $handle, string $slug, ?string $version = null): string
    {
        $version = $this->normalizeVersionSlug($version);
        $versionSegment = $version !== '' ? $version . '/' : '';

        return '/plugins/' . $handle . '/docs/' . $versionSegment . $slug;
    }

    private function buildNavigationFromPages(string $handle, ?string $version = null): ?array
    {
        $pages = $this->getPages($handle, null, $version);
        if ($pages === []) {
            return null;
        }

        $navigation = [];
        $order = 0;

        foreach ($pages as $page) {
            $categoryKey = $page->category ?: 'docs';
            if (!isset($navigation[$categoryKey])) {
                $order++;
                $navigation[$categoryKey] = [
                    'label' => $this->slugToTitle($categoryKey),
                    'order' => $order,
                    'collapsable' => true,
                    'open' => true,
                    'pages' => [],
                ];
            }

            $navigation[$categoryKey]['pages'][] = [
                'slug' => $page->slug,
                'title' => $page->title ?? $this->slugToTitle(basename((string) $page->slug)),
                'description' => $page->description ?? '',
            ];
        }

        return $navigation;
    }

    private function normalizeVersionSlug(?string $version): string
    {
        if ($version === null || trim($version) === '') {
            return '';
        }

        return SlugHandleHelper::normalizePathSlug($version, '');
    }

    private function titleToSlug(string $title): string
    {
        return SlugHandleHelper::normalizeSlug($title, '');
    }

    private function slugToTitle(string $slug): string
    {
        $words = explode('-', $slug);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }

    /**
     * Get page anchors from headings
     *
     * @param SourceDoc $page Page element
     * @return array
     */
    public function getAnchors(SourceDoc $page): array
    {
        return $page->headings ?? [];
    }

    /**
     * Get previous and next pages for navigation
     *
     * @param string $handle Source handle
     * @param string $currentSlug Current page slug
     * @return array{prev: SourceDoc|null, next: SourceDoc|null}
     */
    public function getPrevNextPages(string $handle, string $currentSlug, ?string $version = null): array
    {
        $allPages = $this->getPages($handle, null, $version);
        $currentIndex = null;

        foreach ($allPages as $idx => $page) {
            if ($page->slug === $currentSlug) {
                $currentIndex = $idx;
                break;
            }
        }

        $result = ['prev' => null, 'next' => null];

        if ($currentIndex !== null) {
            if ($currentIndex > 0) {
                $result['prev'] = $allPages[$currentIndex - 1];
            }
            if ($currentIndex < count($allPages) - 1) {
                $result['next'] = $allPages[$currentIndex + 1];
            }
        }

        return $result;
    }

    /**
     * Get parsed changelog for a source
     *
     * @param string $handle Source handle
     * @return array|null
     */
    public function getChangelog(string $handle): ?array
    {
        return DocsManager::getInstance()->changelog->parseChangelog($handle);
    }

    /**
     * Get sync statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return DocsManager::getInstance()->sync->getStats();
    }

    /**
     * Get plugin settings
     *
     * @return \lindemannrock\docsmanager\models\Settings
     */
    public function getSettings()
    {
        return DocsManager::getInstance()->getSettings();
    }
}
