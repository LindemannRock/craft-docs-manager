<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\helpers;

use craft\helpers\FileHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Composes README hero banners via the ImageMagick CLI.
 *
 * This is a development / authoring helper — it shells out to `magick`, so it is
 * never used at runtime. The pipeline mirrors the install experience aesthetic:
 * a dark gradient tinted by the plugin's accent colour, the LindemannRock logo
 * as a faint, large, randomly transformed background mark, the icon badge, and
 * the name/tagline block.
 *
 * @since 5.2.0
 */
final class HeroImageHelper
{
    private const WIDTH = 1280;
    private const HEIGHT = 360;
    private const BASE_BG = '#0B1220';
    private const FONT_BOLD = 'Liberation-Sans-Bold';
    private const FONT_REGULAR = 'Liberation-Sans';

    /**
     * Default accent when an icon yields no usable colour (mirrors the prototype).
     */
    public const FALLBACK_COLOR = '#0F766E';

    /**
     * Locate the ImageMagick CLI, or null if it is not on PATH.
     */
    public static function findMagick(): ?string
    {
        return (new ExecutableFinder())->find('magick');
    }

    /**
     * Generate a 1280×360 webp hero banner.
     *
     * @param string $iconSvg Plugin icon SVG markup.
     * @param string $logoPath Filesystem path to the LindemannRock logo SVG.
     * @param string $color Accent hex colour, e.g. `#820EFF`.
     * @param string $name Banner title.
     * @param string|null $tagline Optional subtitle.
     * @param string $outPath Destination `.webp` path.
     * @throws \RuntimeException If magick is missing or any compositing step fails.
     */
    public static function generate(
        string $iconSvg,
        string $logoPath,
        string $color,
        string $name,
        ?string $tagline,
        string $outPath,
    ): void {
        $magick = self::findMagick();
        if ($magick === null) {
            throw new \RuntimeException('Hero generation requires the ImageMagick CLI ("magick"), which was not found on PATH.');
        }
        if (trim($iconSvg) === '') {
            throw new \RuntimeException('Cannot generate a hero without plugin icon SVG markup.');
        }
        if (!is_file($logoPath)) {
            throw new \RuntimeException("LindemannRock logo not found at: {$logoPath}");
        }

        $color = $color !== '' ? $color : self::FALLBACK_COLOR;
        $tagline = $tagline !== null ? trim($tagline) : null;

        $tmp = self::makeTempDir();

        try {
            // Random mark variation — bigger + randomised scale/rotate/position per
            // run, matching the prototype's $RANDOM ranges.
            $markSize = 1500 + random_int(0, 499);
            $markRot = random_int(0, 359);
            $markGeo = sprintf('%+d%+d', random_int(0, 299) - 110, random_int(0, 159) - 70);

            $iconFile = $tmp . '/icon.svg';
            if (file_put_contents($iconFile, $iconSvg) === false) {
                throw new \RuntimeException("Failed to write temporary icon to: {$iconFile}");
            }

            // 1) icon badge + soft shadow
            self::run($magick, ['-background', 'none', '-density', '320', $iconFile, '-resize', '170x170', "{$tmp}/icon0.png"]);
            self::run($magick, ["{$tmp}/icon0.png", '(', '+clone', '-background', 'black', '-shadow', '55x16+0+12', ')', '+swap', '-background', 'none', '-layers', 'merge', '+repage', "{$tmp}/icon.png"]);

            // 2) LR logo — faint, large, randomly rotated background mark
            self::run($magick, ['-background', 'none', '-density', '220', $logoPath, '-resize', "{$markSize}x{$markSize}", '-rotate', (string)$markRot, '-channel', 'A', '-evaluate', 'multiply', '0.08', '+channel', "{$tmp}/mark.png"]);

            // 3) accent glow behind the icon
            self::run($magick, ['-size', '1200x1200', "radial-gradient:{$color}-none", '-channel', 'A', '-evaluate', 'multiply', '0.50', '+channel', "{$tmp}/glow.png"]);

            // 4) background: base colour + glow (West) + mark (Center, random offset)
            self::run($magick, ['-size', self::WIDTH . 'x' . self::HEIGHT, 'xc:' . self::BASE_BG, "{$tmp}/glow.png", '-gravity', 'West', '-geometry', '-360+0', '-composite', "{$tmp}/mark.png", '-gravity', 'Center', '-geometry', $markGeo, '-composite', "{$tmp}/bg.png"]);

            // 5) name (+ tagline) as ONE trimmed block (tight 6×12 spacer)
            self::run($magick, ['-background', 'none', '-fill', '#FFFFFF', '-font', self::FONT_BOLD, '-pointsize', '54', 'label:' . $name, '-trim', '+repage', "{$tmp}/t-name.png"]);
            if ($tagline !== null && $tagline !== '') {
                self::run($magick, ['-background', 'none', '-fill', '#A9B2C2', '-font', self::FONT_REGULAR, '-pointsize', '22', 'label:' . $tagline, '-trim', '+repage', "{$tmp}/t-tag.png"]);
                self::run($magick, ["{$tmp}/t-name.png", '(', '-size', '6x12', 'xc:none', ')', "{$tmp}/t-tag.png", '-background', 'none', '-gravity', 'West', '-append', "{$tmp}/textblock.png"]);
            } elseif (!copy("{$tmp}/t-name.png", "{$tmp}/textblock.png")) {
                throw new \RuntimeException('Failed to assemble the hero text block.');
            }

            // 6) compose: icon (West) + text block (West, gap from icon) + credit
            FileHelper::createDirectory(dirname($outPath));
            self::run($magick, [
                "{$tmp}/bg.png",
                "{$tmp}/icon.png", '-gravity', 'West', '-geometry', '+84+0', '-composite',
                "{$tmp}/textblock.png", '-gravity', 'West', '-geometry', '+320-5', '-composite',
                '-gravity', 'SouthEast', '-font', self::FONT_REGULAR, '-pointsize', '20', '-fill', '#6B7689', '-annotate', '+36+26', 'lindemannrock.com',
                '-quality', '92', $outPath,
            ]);
        } finally {
            FileHelper::removeDirectory($tmp);
        }
    }

    /**
     * Run a single `magick` invocation, throwing on failure.
     *
     * @param list<string> $args
     */
    private static function run(string $magick, array $args): void
    {
        $process = new Process(array_merge([$magick], $args));
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'ImageMagick step failed: magick ' . implode(' ', $args) . "\n" . trim($process->getErrorOutput()),
            );
        }
    }

    private static function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lr-hero-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($dir);

        return $dir;
    }
}
