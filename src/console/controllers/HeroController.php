<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\docsmanager\helpers\HeroImageHelper;
use yii\console\ExitCode;

/**
 * Generate README hero banners for plugins.
 *
 * Inputs are derived automatically from the plugin: the accent colour from its
 * `src/icon.svg` (via base's icon/colour helpers), the name and tagline from its
 * `composer.json`, and the LindemannRock background mark from base's bundled
 * logo. This is a development / authoring command — it shells out to the
 * ImageMagick CLI and is never used at runtime.
 *
 * @since 5.2.0
 */
class HeroController extends Controller
{
    use PluginPathTrait;

    /**
     * @var string|null Override the banner title (default: composer extra.name).
     */
    public ?string $name = null;

    /**
     * @var string|null Override the tagline (default: composer description, trimmed at " - ").
     */
    public ?string $tagline = null;

    /**
     * @var bool For generate-all: overwrite heroes that already exist.
     */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'name';
        $options[] = 'tagline';

        if ($actionID === 'generate-all') {
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Generate a hero banner for a single plugin.
     *
     * Usage: php craft docs-manager/hero/generate <plugin> [out]
     *
     * @param string $handle Plugin handle or folder name.
     * @param string|null $out Output path (default: {plugin}/docs/images/hero.webp).
     * @return int Exit code
     */
    public function actionGenerate(string $handle, ?string $out = null): int
    {
        if (HeroImageHelper::findMagick() === null) {
            $this->stderr("Error: this command requires the ImageMagick CLI (\"magick\") on PATH.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $resolved = $this->resolvePluginPath($handle);
        if (!$resolved) {
            $this->stderr("Error: Could not resolve plugin \"{$handle}\" — not a known handle or folder.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return $this->generateFor($resolved['path'], $resolved['handle'], $out, $this->name, $this->tagline);
    }

    /**
     * Generate hero banners for every installed plugin that has docs and an icon.
     *
     * Existing heroes are skipped unless --force is given, so this never silently
     * overwrites committed assets.
     *
     * Usage: php craft docs-manager/hero/generate-all [--force]
     *
     * @return int Exit code
     */
    public function actionGenerateAll(): int
    {
        if (HeroImageHelper::findMagick() === null) {
            $this->stderr("Error: this command requires the ImageMagick CLI (\"magick\") on PATH.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            $handle = $plugin->id;

            $resolved = $this->resolvePluginPath($handle);
            if (!$resolved) {
                continue;
            }

            $path = $resolved['path'];

            // Only plugins that ship consumer-facing docs get a hero.
            if (!is_dir($path . '/docs')) {
                continue;
            }

            // No icon → nothing to derive a colour/badge from.
            if (PluginHelper::getIconSvg($plugin) === null) {
                $this->stdout("- {$handle}: skipped (no src/icon.svg)\n", Console::FG_GREY);
                $skipped++;
                continue;
            }

            $out = $path . '/docs/images/hero.webp';
            if (is_file($out) && !$this->force) {
                $this->stdout("- {$handle}: skipped (hero exists; use --force)\n", Console::FG_GREY);
                $skipped++;
                continue;
            }

            if ($this->generateFor($path, $resolved['handle'], $out, null, null) === ExitCode::OK) {
                $generated++;
            } else {
                $failed++;
            }
        }

        $this->stdout("\nDone — {$generated} generated, {$skipped} skipped, {$failed} failed.\n", $failed > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Resolve inputs for a plugin and compose its hero.
     *
     * @return int Exit code
     */
    private function generateFor(string $path, string $handle, ?string $out, ?string $nameOverride, ?string $taglineOverride): int
    {
        // Icon + accent colour come from base's Step-1 helpers, which need the
        // installed plugin object.
        $plugin = Craft::$app->getPlugins()->getPlugin($handle);
        if ($plugin === null) {
            $this->stderr("Error: plugin \"{$handle}\" is not installed/enabled — its icon cannot be read.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $iconSvg = PluginHelper::getIconSvg($plugin);
        if ($iconSvg === null) {
            $this->stderr("Error: plugin \"{$handle}\" has no readable src/icon.svg.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $color = ColorHelper::primaryHexFromSvg($iconSvg) ?? HeroImageHelper::FALLBACK_COLOR;

        $composer = $this->readComposer($path);
        $name = $nameOverride ?? ($composer['extra']['name'] ?? null) ?? $this->labelFromHandle($handle);
        $tagline = $taglineOverride ?? $this->taglineFromDescription($composer['description'] ?? '');

        $logoPath = PluginHelper::lrLogoFile();
        $out ??= $path . '/docs/images/hero.webp';

        try {
            HeroImageHelper::generate($iconSvg, $logoPath, $color, $name, $tagline, $out);
        } catch (\RuntimeException $e) {
            $this->stderr("Error generating hero for \"{$handle}\": {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ hero → {$out}\n", Console::FG_GREEN);
        $this->stdout("  name='{$name}' tagline='" . ($tagline ?? '') . "' colour={$color}\n");

        return ExitCode::OK;
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(string $path): array
    {
        $composerPath = $path . '/composer.json';
        if (!is_file($composerPath)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($composerPath), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Take the composer description up to the first " - " separator.
     */
    private function taglineFromDescription(string $description): ?string
    {
        $tagline = trim(explode(' - ', $description, 2)[0]);

        return $tagline !== '' ? $tagline : null;
    }

    /**
     * Title-case a handle as a name fallback (e.g. "sms-manager" → "Sms Manager").
     */
    private function labelFromHandle(string $handle): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $handle));
    }
}
