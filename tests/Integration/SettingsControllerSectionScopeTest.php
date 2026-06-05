<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\controllers\SettingsController;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.1.0
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerSectionScopeTest extends TestCase
{
    public function testSettingsSectionsMatchRenderedFormScopes(): void
    {
        $controller = new SettingsController('settings', DocsManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        $expected = [
            'general' => [
                'pluginName',
                'enabledSites',
                'defaultSourceType',
                'localPluginBasePath',
                'githubToken',
                'logLevel',
            ],
            'interface' => [
                'itemsPerPage',
                'timeFormat',
                'monthFormat',
                'dateOrder',
                'dateSeparator',
                'showSeconds',
            ],
            'frontend' => [
                'enableSyntaxHighlighting',
                'codeTheme',
                'codeFontSize',
                'codeFontFamily',
                'codeShowLineNumbers',
                'codeEnableCopyButton',
                'enableAnchorGeneration',
            ],
            'advanced' => [
                'autoSync',
                'syncSchedule',
            ],
            'field-layout' => [],
        ];

        foreach ($expected as $section => $attributes) {
            self::assertSame($attributes, $method->invoke($controller, $section), "Unexpected {$section} settings scope.");
        }
    }
}
