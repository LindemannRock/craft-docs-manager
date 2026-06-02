<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use Craft;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\docsmanager\tests\TestCase;

/**
 * Covers Docs Manager's adoption of the shared slug/handle primitives.
 *
 * @since 5.1.0
 */
final class SlugHandleRolloutTest extends TestCase
{
    /**
     * @var list<int>
     */
    private array $sourceIds = [];

    /**
     * @var list<int>
     */
    private array $elementIds = [];

    /**
     * @var list<string>
     */
    private array $fixtureRoots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->elementIds) as $elementId) {
            $element = SourceDoc::find()->id($elementId)->status(null)->one()
                ?? PluginPage::find()->id($elementId)->status(null)->one();
            if ($element !== null) {
                Craft::$app->getElements()->deleteElement($element, true);
            }
        }

        foreach (array_reverse($this->sourceIds) as $sourceId) {
            SourceRecord::deleteAll(['id' => $sourceId]);
        }

        foreach (array_reverse($this->fixtureRoots) as $root) {
            $this->deleteDirectory($root);
        }

        parent::tearDown();
    }

    public function testSourceHandleNormalizesBeforeSave(): void
    {
        $source = $this->createSource('DM Test Source', 'DM Test Source');

        $this->assertSame('dm-test-source', $source->handle);
    }

    public function testCustomPageSlugNormalizesAsPathSlug(): void
    {
        $source = $this->createSource('dm-test-custom-page', 'DM Test Custom Page');

        $page = new PluginPage();
        $page->title = 'Docs Test Page';
        $page->sourceId = (int)$source->id;
        $page->pageType = 'custom';
        $page->slug = ' Guides / My Test Page ';
        $page->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $page->setEnabledForSite(true);

        $this->assertTrue(
            Craft::$app->getElements()->saveElement($page),
            'Custom page should save: ' . json_encode($page->getErrors()),
        );
        $this->elementIds[] = (int)$page->id;

        $this->assertSame('guides/my-test-page', $page->slug);
    }

    public function testSyncReportsSidebarPathsThatNormalizeToSameSlug(): void
    {
        $root = $this->createDocsFixture([
            'Getting Started' => '# Getting Started',
            'getting-started' => '# Duplicate Getting Started',
        ], [
            ['title' => 'Get Started', 'children' => ['Getting Started', 'getting-started']],
        ]);
        $source = $this->createSource('dm-test-sync-collision', 'DM Test Sync Collision', $root);

        $result = DocsManager::getInstance()->sync->syncPlugin((string)$source->handle);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString(
            "normalized slug 'getting-started' is duplicated",
            implode("\n", $result['errors']),
        );

        foreach (SourceDoc::find()->sourceId((int)$source->id)->status(null)->all() as $page) {
            $this->elementIds[] = (int)$page->id;
        }
    }

    private function createSource(string $handle, string $name, ?string $localPath = null): SourceRecord
    {
        $source = new SourceRecord();
        $source->name = $name;
        $source->handle = $handle;
        $source->kind = 'plugin';
        $source->sourceType = 'local';
        $source->localPath = $localPath ?? sys_get_temp_dir();
        $source->enabled = true;

        $this->assertTrue($source->save(), 'Source should save: ' . json_encode($source->getErrors()));
        $this->sourceIds[] = (int)$source->id;

        return $source;
    }

    /**
     * @param array<string, string> $files
     * @param array<int, array{title: string, children: array<int, string>}> $sidebar
     */
    private function createDocsFixture(array $files, array $sidebar): string
    {
        $root = sys_get_temp_dir() . '/docs-manager-test-' . uniqid('', true);
        $this->fixtureRoots[] = $root;
        $docsRoot = $root . '/docs';
        mkdir($docsRoot, 0777, true);

        file_put_contents($docsRoot . '/.sidebar.json', json_encode($sidebar));
        foreach ($files as $path => $content) {
            $fullPath = $docsRoot . '/' . $path . '.md';
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($fullPath, $content);
        }

        return $root;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
            } elseif (is_file($itemPath)) {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
