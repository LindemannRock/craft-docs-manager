<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\docsmanager\helpers\HeroImageHelper;
use lindemannrock\docsmanager\tests\TestCase;

/**
 * Pins the success path of {@see HeroImageHelper::generate()}: a valid
 * 1280×360 webp banner is produced from an icon SVG + the base logo.
 *
 * @since 5.2.0
 */
final class HeroImageGenerationTest extends TestCase
{
    public function testGeneratesValidWebpBanner(): void
    {
        if (HeroImageHelper::findMagick() === null) {
            self::markTestSkipped('ImageMagick CLI ("magick") is not available.');
        }

        // LR logo is resolved the same way the command does — base owns its
        // location.
        $logoPath = PluginHelper::lrLogoFile();
        self::assertFileExists($logoPath);

        $out = $this->createTrackedTempDirectory('__docsmanager_test_hero') . '/hero.webp';

        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<rect width="24" height="24" fill="#FFFFFF"/>'
            . '<path fill="#1A73E8" d="M4 4h16v16H4z"/></svg>';

        HeroImageHelper::generate($iconSvg, $logoPath, '#1A73E8', 'Test Plugin', 'A test tagline', $out);

        self::assertFileExists($out);

        $info = getimagesize($out);
        self::assertNotFalse($info, 'Generated hero is not a readable image.');
        self::assertSame(1280, $info[0], 'Hero width should be 1280.');
        self::assertSame(360, $info[1], 'Hero height should be 360.');
        self::assertSame(IMAGETYPE_WEBP, $info[2], 'Hero should be a webp.');
    }
}
