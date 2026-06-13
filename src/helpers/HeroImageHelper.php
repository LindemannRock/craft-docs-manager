<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\helpers;

use craft\helpers\FileHelper;
use lindemannrock\base\helpers\ColorHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Composes README hero banners via the ImageMagick CLI.
 *
 * This is a development / authoring helper — it shells out to `magick`, so it is
 * never used at runtime. Everything is derived from the plugin icon:
 *  - the ACCENT (most saturated icon colour) drives a smooth gradient;
 *  - the INK (the glyph colour) is the title text, and decides the gradient
 *    direction so the text always lands on a contrasting field — dark ink gets a
 *    bright field under the text, light ink gets a deep one;
 *  - the subtitle/credit are the ink dimmed (neutral on any hue);
 *  - the icon shadow is a deep, accent-tinted tone, softened.
 *
 * @since 5.2.0
 */
final class HeroImageHelper
{
    private const WIDTH = 1280;
    private const HEIGHT = 360;
    private const FONT_BOLD = 'Liberation-Sans-Bold';
    private const FONT_REGULAR = 'Liberation-Sans';

    /**
     * Default accent when an icon yields no usable colour.
     */
    public const FALLBACK_COLOR = '#0F766E';

    public const STYLE_PRIMARY = 'primary';
    public const STYLE_LIGHTER = 'lighter';
    public const STYLE_DEEPER = 'deeper';
    public const STYLE_DIAGONAL = 'diagonal';

    /**
     * Selectable gradient styles, in menu order.
     */
    public const STYLES = [self::STYLE_PRIMARY, self::STYLE_LIGHTER, self::STYLE_DEEPER, self::STYLE_DIAGONAL];

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
     * @param string $accent Accent hex colour (the icon's badge/fill), e.g. `#820EFF`.
     * @param string $ink Ink hex colour (the icon's glyph) used for the title text.
     * @param string $iconSvg Plugin icon SVG markup (rasterised for the badge).
     * @param string $name Banner title.
     * @param string|null $tagline Optional subtitle.
     * @param string $outPath Destination `.webp` path.
     * @param string $style One of self::STYLES (default: lighter).
     * @throws \RuntimeException If magick is missing or any compositing step fails.
     */
    public static function generate(
        string $accent,
        string $ink,
        string $iconSvg,
        string $name,
        ?string $tagline,
        string $outPath,
        string $style = self::STYLE_LIGHTER,
    ): void {
        $magick = self::findMagick();
        if ($magick === null) {
            throw new \RuntimeException('Hero generation requires the ImageMagick CLI ("magick"), which was not found on PATH.');
        }
        if (trim($iconSvg) === '') {
            throw new \RuntimeException('Cannot generate a hero without plugin icon SVG markup.');
        }

        $accent = $accent !== '' ? $accent : self::FALLBACK_COLOR;
        $ink = $ink !== '' ? $ink : '#FFFFFF';
        if (!in_array($style, self::STYLES, true)) {
            $style = self::STYLE_LIGHTER;
        }
        $tagline = $tagline !== null ? trim($tagline) : null;

        // Gradient endpoints + direction are coupled to the ink: dark ink wants a
        // bright field under the text (so dark text reads), light ink wants a deep one.
        $darkInk = ColorHelper::luminance($ink) < 128;
        $dk = static fn(int $n): string => ColorHelper::mix($accent, '#000000', $n / 100);
        $lt = static fn(int $n): string => ColorHelper::mix($accent, '#FFFFFF', $n / 100);

        if ($darkInk) {
            [$c0, $c1] = match ($style) {
                self::STYLE_PRIMARY, self::STYLE_DIAGONAL => [$dk(62), $lt(8)],
                self::STYLE_DEEPER => [$dk(58), $dk(8)],
                default => [$dk(45), $lt(8)],   // lighter
            };
        } else {
            [$c0, $c1] = match ($style) {
                self::STYLE_PRIMARY, self::STYLE_DIAGONAL => [$dk(45), $dk(84)],
                self::STYLE_DEEPER => [$dk(55), $dk(90)],
                default => [$dk(32), $dk(78)],  // lighter
            };
        }
        $direction = $style === self::STYLE_DIAGONAL ? 'northeast' : 'east';

        // Title = the ink; subtitle/credit = the ink dimmed (neutral on any hue).
        $title = $ink;
        $sub = ColorHelper::withAlpha($ink, 0.78);
        $credit = ColorHelper::withAlpha($ink, 0.56);
        // Icon shadow: a deep, accent-tinted tone — a colour, not flat black.
        $shadow = ColorHelper::mix($accent, '#000000', 0.82);

        $tmp = self::makeTempDir();

        try {
            $iconFile = $tmp . '/icon.svg';
            if (file_put_contents($iconFile, $iconSvg) === false) {
                throw new \RuntimeException("Failed to write temporary icon to: {$iconFile}");
            }

            // 1) background: smooth IM-native gradient + a faint grain so subtle
            //    dark gradients can't band (librsvg's url(#gradient) renders black).
            self::run($magick, ['-size', self::WIDTH . 'x' . self::HEIGHT, '-define', "gradient:direction={$direction}", "gradient:{$c0}-{$c1}", '-attenuate', '0.12', '+noise', 'Gaussian', "{$tmp}/bg.png"]);

            // 2) icon badge + soft, accent-tinted shadow
            self::run($magick, ['-background', 'none', '-density', '320', $iconFile, '-resize', '170x170', "{$tmp}/i0.png"]);
            self::run($magick, ["{$tmp}/i0.png", '(', '+clone', '-background', $shadow, '-shadow', '40x18+0+10', ')', '+swap', '-background', 'none', '-layers', 'merge', '+repage', "{$tmp}/icon.png"]);

            // 3) name (title = ink) + tagline (ink dimmed) as ONE trimmed block
            self::run($magick, ['-background', 'none', '-fill', $title, '-font', self::FONT_BOLD, '-pointsize', '54', 'label:' . $name, '-trim', '+repage', "{$tmp}/t-name.png"]);
            if ($tagline !== null && $tagline !== '') {
                self::run($magick, ['-background', 'none', '-fill', $sub, '-font', self::FONT_REGULAR, '-pointsize', '22', 'label:' . $tagline, '-trim', '+repage', "{$tmp}/t-tag.png"]);
                self::run($magick, ["{$tmp}/t-name.png", '(', '-size', '6x12', 'xc:none', ')', "{$tmp}/t-tag.png", '-background', 'none', '-gravity', 'West', '-append', "{$tmp}/textblock.png"]);
            } elseif (!copy("{$tmp}/t-name.png", "{$tmp}/textblock.png")) {
                throw new \RuntimeException('Failed to assemble the hero text block.');
            }

            // 4) compose: icon (West) + text block (West, gap from icon) + credit
            FileHelper::createDirectory(dirname($outPath));
            self::run($magick, [
                "{$tmp}/bg.png",
                "{$tmp}/icon.png", '-gravity', 'West', '-geometry', '+84+0', '-composite',
                "{$tmp}/textblock.png", '-gravity', 'West', '-geometry', '+320-5', '-composite',
                '-gravity', 'SouthEast', '-font', self::FONT_REGULAR, '-pointsize', '20', '-fill', $credit, '-annotate', '+36+26', 'lindemannrock.com',
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
