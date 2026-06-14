<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\docsmanager\services\SyncService;
use lindemannrock\docsmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Pins SyncService's auto-registration of a local docs source: on first sync a
 * handle that resolves to a real plugin directory under the configured base
 * path is onboarded automatically, while a handle with no directory (or no
 * composer.json) is not — so a typo can't spawn a junk source.
 *
 * @since 5.2.0
 */
final class SourceAutoRegisterTest extends TestCase
{
    /**
     * @var list<int>
     */
    private array $sourceIds = [];

    private ?string $originalBasePath = null;
    private ?string $originalSourceType = null;
    private bool $settingsTouched = false;

    protected function tearDown(): void
    {
        try {
            if ($this->settingsTouched) {
                $settings = DocsManager::$plugin->getSettings();
                $settings->localPluginBasePath = $this->originalBasePath;
                $settings->defaultSourceType = (string) $this->originalSourceType;
            }
            parent::tearDown();
        } finally {
            foreach (array_reverse($this->sourceIds) as $id) {
                SourceRecord::deleteAll(['id' => $id]);
            }
        }
    }

    public function testAutoRegistersLocalSourceForRealPluginDir(): void
    {
        $base = $this->createTrackedTempDirectory('docs-manager-autoreg-');
        $handle = 'dm-autoreg-real';
        $this->makePluginDir($base, $handle);
        $this->useLocalBase($base);

        self::assertNull(SourceRecord::findOne(['handle' => $handle]), 'No source should exist yet.');

        $source = $this->getOrCreate($handle);

        self::assertNotNull($source, 'Sync should auto-register a local source for a real plugin dir.');
        $this->sourceIds[] = (int) $source->id;
        self::assertSame($handle, $source->handle);
        self::assertSame('plugin', $source->kind);
        self::assertSame('local', $source->sourceType);
        self::assertSame($base . '/' . $handle, $source->localPath);
        self::assertTrue((bool) $source->enabled);
    }

    public function testDoesNotRegisterWhenDirectoryMissing(): void
    {
        $base = $this->createTrackedTempDirectory('docs-manager-autoreg-');
        $handle = 'dm-autoreg-missing';   // deliberately never created on disk
        $this->useLocalBase($base);

        $source = $this->getOrCreate($handle);

        self::assertNull($source, 'A handle with no directory must not auto-create a source.');
        self::assertNull(SourceRecord::findOne(['handle' => $handle]));
    }

    public function testDoesNotRegisterDirectoryWithoutComposerJson(): void
    {
        $base = $this->createTrackedTempDirectory('docs-manager-autoreg-');
        $handle = 'dm-autoreg-nocomposer';
        mkdir($base . '/' . $handle . '/docs', 0777, true);   // dir exists, but no composer.json
        $this->useLocalBase($base);

        $source = $this->getOrCreate($handle);

        self::assertNull($source, 'A directory without composer.json is not a plugin — no source.');
        self::assertNull(SourceRecord::findOne(['handle' => $handle]));
    }

    private function getOrCreate(string $handle): ?SourceRecord
    {
        $method = new ReflectionMethod(SyncService::class, 'getOrCreatePlugin');
        $method->setAccessible(true);

        /** @var SourceRecord|null $result */
        $result = $method->invoke(DocsManager::getInstance()->sync, $handle);

        return $result;
    }

    private function useLocalBase(string $base): void
    {
        $settings = DocsManager::$plugin->getSettings();
        $this->originalBasePath = $settings->localPluginBasePath;
        $this->originalSourceType = $settings->defaultSourceType;
        $this->settingsTouched = true;
        $settings->localPluginBasePath = $base;
        $settings->defaultSourceType = 'local';
    }

    private function makePluginDir(string $base, string $handle): void
    {
        $dir = $base . '/' . $handle;
        mkdir($dir . '/docs', 0777, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => "lindemannrock/{$handle}"]));
    }
}
