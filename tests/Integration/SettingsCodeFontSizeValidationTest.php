<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\models\Settings;
use lindemannrock\docsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.1.0
 */
#[CoversClass(Settings::class)]
final class SettingsCodeFontSizeValidationTest extends TestCase
{
    public function testCodeFontSizeMatchesRenderedFieldBounds(): void
    {
        $settings = new Settings();

        $settings->codeFontSize = 7;
        self::assertFalse($settings->validate(['codeFontSize']));
        self::assertNotEmpty($settings->getErrors('codeFontSize'));

        $settings->clearErrors();
        $settings->codeFontSize = 8;
        self::assertTrue($settings->validate(['codeFontSize']));

        $settings->codeFontSize = 32;
        self::assertTrue($settings->validate(['codeFontSize']));

        $settings->codeFontSize = 33;
        self::assertFalse($settings->validate(['codeFontSize']));
        self::assertNotEmpty($settings->getErrors('codeFontSize'));
    }
}
