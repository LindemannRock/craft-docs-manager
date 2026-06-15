<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\variables;

use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\helpers\HeroImageHelper;
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
     * Get CSS custom properties for a plugin hero from the synced icon SVG.
     *
     * Mirrors the colour decisions used by the hero image generator: the icon
     * accent drives the gradient, while the icon ink drives readable text and
     * determines whether the gradient should land on a light or dark field.
     *
     * @since 5.2.0
     */
    public function getPluginHeroStyle(string $handle, string $style = HeroImageHelper::STYLE_LIGHTER): string
    {
        $plugin = $this->getPlugin($handle);
        $iconSvg = is_array($plugin) ? ($plugin['iconSvg'] ?? null) : null;
        $roles = ColorHelper::iconColorRoles(is_string($iconSvg) ? $iconSvg : null);

        $accent = $roles !== null ? $roles['accent'] : HeroImageHelper::FALLBACK_COLOR;
        $ink = $roles !== null ? $roles['ink'] : '#FFFFFF';

        if (!in_array($style, HeroImageHelper::STYLES, true)) {
            $style = HeroImageHelper::STYLE_LIGHTER;
        }

        $darkInk = ColorHelper::luminance($ink) < 128;
        $dk = static fn(int $n): string => ColorHelper::mix($accent, '#000000', $n / 100);
        $lt = static fn(int $n): string => ColorHelper::mix($accent, '#FFFFFF', $n / 100);

        if ($darkInk) {
            [$from, $to] = match ($style) {
                HeroImageHelper::STYLE_PRIMARY, HeroImageHelper::STYLE_DIAGONAL => [$dk(62), $lt(8)],
                HeroImageHelper::STYLE_DEEPER => [$dk(58), $dk(8)],
                default => [$dk(45), $lt(8)],
            };
        } else {
            [$from, $to] = match ($style) {
                HeroImageHelper::STYLE_PRIMARY, HeroImageHelper::STYLE_DIAGONAL => [$dk(45), $dk(84)],
                HeroImageHelper::STYLE_DEEPER => [$dk(55), $dk(90)],
                default => [$dk(32), $dk(78)],
            };
        }

        $shadow = ColorHelper::mix($accent, '#000000', 0.82);
        $buttonText = $darkInk ? $to : $from;
        $badgeBackground = $darkInk ? ColorHelper::withAlpha('#FFFFFF', 0.34) : ColorHelper::withAlpha($ink, 0.18);
        $badgeBorder = $darkInk ? ColorHelper::withAlpha($ink, 0.10) : ColorHelper::withAlpha($ink, 0.22);
        $ld = static fn(string $light, string $dark): string => 'light-dark(' . $light . ',' . $dark . ')';

        $lightText = $darkInk ? ColorHelper::mix($ink, '#000000', 0.08) : ColorHelper::mix($accent, '#000000', 0.82);
        $lightMuted = ColorHelper::mix($lightText, '#FFFFFF', 0.36);
        $darkText = $darkInk ? ColorHelper::mix($accent, '#FFFFFF', 0.88) : ColorHelper::mix($ink, '#FFFFFF', 0.08);
        $darkMuted = ColorHelper::mix($darkText, '#000000', 0.34);

        $lightPage = ColorHelper::mix($accent, '#FFFFFF', 0.99);
        $lightBg = ColorHelper::mix($accent, '#FFFFFF', 0.97);
        $lightBgSecondary = ColorHelper::mix($accent, '#FFFFFF', 0.90);
        $lightBgHover = ColorHelper::mix($accent, '#FFFFFF', 0.86);
        $lightHairline = ColorHelper::mix($accent, '#FFFFFF', 0.70);

        $darkPage = ColorHelper::mix($accent, '#000000', 0.88);
        $darkBg = ColorHelper::mix($accent, '#000000', 0.80);
        $darkBgSecondary = ColorHelper::mix($accent, '#000000', 0.72);
        $darkBgHover = ColorHelper::mix($accent, '#000000', 0.62);
        $darkHairline = ColorHelper::mix($accent, '#000000', 0.54);

        $shellAccent = self::readableBrandColor($accent, $lightPage, false);
        $shellAccentDark = self::readableBrandColor(ColorHelper::mix($shellAccent, '#000000', 0.12), $lightBgSecondary, false);
        $shellAccentHover = self::readableBrandColor(ColorHelper::mix($shellAccentDark, '#000000', 0.10), $lightBgHover, false);
        $shellAccentLight = self::readableBrandColor($accent, $darkPage, true);
        $shellAccentLighter = self::readableBrandColor(ColorHelper::mix($shellAccentLight, '#FFFFFF', 0.12), $darkBgSecondary, true);
        $shellAccentLightHover = self::readableBrandColor(ColorHelper::mix($shellAccentLighter, '#FFFFFF', 0.10), $darkBgHover, true);
        $shellButtonTextLight = self::readableTextFor($shellAccent);
        $shellButtonTextDark = self::readableTextFor($shellAccentLight);

        $codeLightBg = ColorHelper::mix($accent, '#FFFFFF', 0.84);
        $codeDarkBg = ColorHelper::mix($accent, '#000000', 0.74);
        $preLightBg = ColorHelper::mix($accent, '#000000', 0.80);
        $preDarkBg = ColorHelper::mix($accent, '#000000', 0.92);
        $preLightText = ColorHelper::mix($accent, '#FFFFFF', 0.90);
        $preDarkText = ColorHelper::mix($accent, '#FFFFFF', 0.82);

        $positiveLight = '#047857';
        $positiveDark = '#6ee7b7';
        $infoLight = $shellAccent;
        $infoDark = $shellAccentLight;
        $warningLight = '#92400e';
        $warningDark = '#fbbf24';
        $dangerLight = '#991b1b';
        $dangerDark = '#f87171';

        return implode(';', [
            '--plugin-hero-accent:' . $accent,
            '--plugin-hero-ink:' . $ink,
            '--plugin-hero-ink-muted:' . ColorHelper::withAlpha($ink, 0.78),
            '--plugin-hero-credit:' . ColorHelper::withAlpha($ink, 0.56),
            '--plugin-hero-from:' . $from,
            '--plugin-hero-to:' . $to,
            '--plugin-hero-shadow:' . $shadow,
            '--plugin-hero-button-text:' . $buttonText,
            '--plugin-hero-soft-surface:' . ColorHelper::withAlpha($ink, $darkInk ? 0.08 : 0.16),
            '--plugin-hero-badge-bg:' . $badgeBackground,
            '--plugin-hero-badge-border:' . $badgeBorder,
            '--plugin-hero-badge-text:' . $ink,
            '--plugin-shell-bg:' . $ld($lightBg, $darkBg),
            '--plugin-shell-bg-secondary:' . $ld($lightBgSecondary, $darkBgSecondary),
            '--plugin-shell-bg-hover:' . $ld($lightBgHover, $darkBgHover),
            '--plugin-shell-bg-page:' . $ld($lightPage, $darkPage),
            '--plugin-shell-text:' . $ld($lightText, $darkText),
            '--plugin-shell-text-secondary:' . $ld($lightMuted, $darkMuted),
            '--plugin-shell-text-muted:' . $ld(ColorHelper::withAlpha($lightText, 0.68), ColorHelper::withAlpha($darkText, 0.68)),
            '--plugin-shell-text-disabled:' . $ld(ColorHelper::withAlpha($lightText, 0.44), ColorHelper::withAlpha($darkText, 0.44)),
            '--plugin-shell-hairline:' . $ld(ColorHelper::withAlpha($lightHairline, 0.86), ColorHelper::withAlpha($darkHairline, 0.92)),
            '--plugin-shell-hairline-light:' . $ld(ColorHelper::withAlpha($lightText, 0.18), ColorHelper::withAlpha($darkText, 0.18)),
            '--plugin-shell-accent:' . $ld($shellAccent, $shellAccentLight),
            '--plugin-shell-accent-dark:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-accent-bg:' . $ld(ColorHelper::withAlpha($accent, 0.18), ColorHelper::withAlpha($accent, 0.20)),
            '--plugin-shell-accent-hover:' . $ld($shellAccentHover, $shellAccentLightHover),
            '--plugin-shell-focus:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-selection-bg:' . $ld(ColorHelper::withAlpha($accent, 0.30), ColorHelper::withAlpha($accent, 0.34)),
            '--plugin-shell-selection-text:' . $ld($lightText, $darkText),
            '--plugin-shell-caret:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-accent-input:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-code:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-code-bg:' . $ld(ColorHelper::withAlpha($codeLightBg, 0.78), ColorHelper::withAlpha($codeDarkBg, 0.86)),
            '--plugin-shell-code-border:' . $ld(ColorHelper::withAlpha($lightText, 0.16), ColorHelper::withAlpha($darkText, 0.16)),
            '--plugin-shell-pre-code:' . $ld($preLightText, $preDarkText),
            '--plugin-shell-pre-bg:' . $ld($preLightBg, $preDarkBg),
            '--plugin-shell-since-bg:' . $ld(ColorHelper::withAlpha($accent, 0.16), ColorHelper::withAlpha($accent, 0.22)),
            '--plugin-shell-since-text:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-btn-bg:' . $ld($shellAccent, $shellAccentLight),
            '--plugin-shell-btn-hover:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-btn-text:' . $ld($shellButtonTextLight, $shellButtonTextDark),
            '--plugin-shell-scrollbar:' . $ld($lightHairline, $darkHairline),
            '--plugin-shell-release-latest-bg:' . $ld(ColorHelper::withAlpha('#10B981', 0.16), ColorHelper::withAlpha('#10B981', 0.22)),
            '--plugin-shell-release-latest-text:' . $ld($positiveLight, $positiveDark),
            '--plugin-shell-changelog-link:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-changelog-link-hover:' . $ld($shellAccentHover, $shellAccentLightHover),
            '--plugin-shell-callout-note:' . $ld($infoLight, $infoDark),
            '--plugin-shell-callout-tip:' . $ld($positiveLight, $positiveDark),
            '--plugin-shell-callout-warning:' . $ld($warningLight, $warningDark),
            '--plugin-shell-callout-caution:' . $ld($dangerLight, $dangerDark),
            '--plugin-shell-callout-important:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-change-added:' . $ld($positiveLight, $positiveDark),
            '--plugin-shell-change-added-border:' . $ld(ColorHelper::withAlpha('#10B981', 0.72), ColorHelper::withAlpha('#34D399', 0.74)),
            '--plugin-shell-change-improved:' . $ld($infoLight, $infoDark),
            '--plugin-shell-change-improved-border:' . $ld(ColorHelper::withAlpha($accent, 0.72), ColorHelper::withAlpha($accent, 0.74)),
            '--plugin-shell-change-fixed:' . $ld($warningLight, $warningDark),
            '--plugin-shell-change-fixed-border:' . $ld(ColorHelper::withAlpha('#F59E0B', 0.72), ColorHelper::withAlpha('#FBBF24', 0.74)),
            '--plugin-shell-change-removed:' . $ld($dangerLight, $dangerDark),
            '--plugin-shell-change-removed-border:' . $ld(ColorHelper::withAlpha('#EF4444', 0.72), ColorHelper::withAlpha('#F87171', 0.74)),
            '--plugin-shell-copy-btn-bg:' . $ld(ColorHelper::mix($accent, '#000000', 0.74), ColorHelper::mix($accent, '#000000', 0.72)),
            '--plugin-shell-copy-btn-color:' . $ld($preLightText, $preDarkText),
            '--plugin-shell-copy-btn-hover-bg:' . $ld(ColorHelper::mix($accent, '#000000', 0.64), ColorHelper::mix($accent, '#000000', 0.62)),
            '--plugin-shell-copy-btn-focus-color:' . $ld($shellAccentDark, $shellAccentLighter),
            '--plugin-shell-code-bg-token:' . $ld($preLightBg, $preDarkBg),
            '--plugin-shell-code-fg-token:' . $ld($preLightText, $preDarkText),
        ]);
    }

    /**
     * Get a source's mask icon SVG for theme-coloured UI surfaces.
     *
     * Synced sources store this in metadata. Local sources can fall back to
     * `src/icon-mask.svg` until their next sync writes the metadata value.
     *
     * @since 5.2.0
     */
    public function getIconMaskSvg(string $handle): ?string
    {
        $source = $this->getSource($handle);
        if (!$source) {
            return null;
        }

        $metadata = $source['metadata'] ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded) && is_string($decoded['iconMaskSvg'] ?? null) && $decoded['iconMaskSvg'] !== '') {
                return $decoded['iconMaskSvg'];
            }
        } elseif (is_array($metadata) && is_string($metadata['iconMaskSvg'] ?? null) && $metadata['iconMaskSvg'] !== '') {
            return $metadata['iconMaskSvg'];
        }

        $basePath = !empty($source['localPath'])
            ? LocalSourcePathHelper::resolve((string) $source['localPath'])
            : LocalSourcePathHelper::join('@root/plugins', $handle);
        $maskPath = $basePath . '/src/icon-mask.svg';

        return is_file($maskPath) ? file_get_contents($maskPath) ?: null : null;
    }

    private static function readableBrandColor(string $accent, string $background, bool $towardLight, float $minimumRatio = 4.5): string
    {
        $target = $towardLight ? '#FFFFFF' : '#000000';

        for ($step = 0; $step <= 100; $step += 4) {
            $candidate = ColorHelper::mix($accent, $target, $step / 100);
            if (self::contrastRatio($candidate, $background) >= $minimumRatio) {
                return $candidate;
            }
        }

        $fallback = $towardLight ? '#F9FAFB' : '#111827';
        if (self::contrastRatio($fallback, $background) >= $minimumRatio) {
            return $fallback;
        }

        return $towardLight ? '#FFFFFF' : '#000000';
    }

    private static function readableTextFor(string $background): string
    {
        $light = '#FFFFFF';
        $dark = '#111827';

        return self::contrastRatio($light, $background) >= self::contrastRatio($dark, $background)
            ? $light
            : $dark;
    }

    private static function contrastRatio(string $foreground, string $background): float
    {
        $light = max(self::relativeLuminance($foreground), self::relativeLuminance($background));
        $dark = min(self::relativeLuminance($foreground), self::relativeLuminance($background));

        return ($light + 0.05) / ($dark + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) {
            return 0.0;
        }

        $channels = array_map(
            static function(int $channel): float {
                $value = $channel / 255;

                return $value <= 0.03928
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            },
            $rgb,
        );

        return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
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
