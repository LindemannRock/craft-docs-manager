<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\helpers\LocalSourcePathHelper;
use lindemannrock\docsmanager\models\Settings;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\docsmanager\tests\TestCase;

/**
 * Pins local source path validation and runtime resolution.
 */
final class LocalSourcePathTest extends TestCase
{
    private const BASE_ENV = 'LR_DOCS_MANAGER_BASE_PATH_TEST';
    private const SOURCE_ENV = 'LR_DOCS_MANAGER_SOURCE_PATH_TEST';

    protected function tearDown(): void
    {
        foreach ([self::BASE_ENV, self::SOURCE_ENV] as $envName) {
            putenv($envName);
            unset($_ENV[$envName], $_SERVER[$envName]);
        }

        parent::tearDown();
    }

    public function testLocalPluginBasePathEnvVarValidatesAndResolves(): void
    {
        $basePath = $this->makeTempDir();
        $this->setEnvValue(self::BASE_ENV, $basePath);

        $settings = new Settings();
        $settings->defaultSourceType = 'local';
        $settings->localPluginBasePath = '$' . self::BASE_ENV;

        self::assertTrue($settings->validate(['localPluginBasePath']));
        self::assertSame($basePath, LocalSourcePathHelper::resolve($settings->localPluginBasePath));
    }

    public function testSourceLocalPathEnvVarValidatesAndResolves(): void
    {
        $sourcePath = $this->makeTempDir();
        $this->setEnvValue(self::SOURCE_ENV, $sourcePath);

        $source = new SourceRecord();
        $source->sourceType = 'local';
        $source->localPath = '$' . self::SOURCE_ENV;

        self::assertTrue($source->validate(['localPath']));
        self::assertSame($sourcePath, LocalSourcePathHelper::resolve((string) $source->localPath));
    }

    public function testLocalPluginBasePathStillRequiresExistingDirectory(): void
    {
        $settings = new Settings();
        $settings->defaultSourceType = 'local';
        $settings->localPluginBasePath = $this->makeTempDir() . '/missing';

        self::assertFalse($settings->validate(['localPluginBasePath']));
        self::assertStringContainsString('does not exist', implode(' ', $settings->getErrors('localPluginBasePath')));
    }

    private function makeTempDir(): string
    {
        return $this->createTrackedTempDirectory('docs-manager-path-');
    }

    private function setEnvValue(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
