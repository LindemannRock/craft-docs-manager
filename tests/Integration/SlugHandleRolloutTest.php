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

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach (array_reverse($this->sourceIds) as $sourceId) {
                SourceRecord::deleteAll(['id' => $sourceId]);
            }
        }
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

        $this->saveTestElement($page, true);

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
            $this->trackElementForCleanup((int)$page->id);
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
        $root = $this->createTrackedTempDirectory('docs-manager-test-');
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
}
