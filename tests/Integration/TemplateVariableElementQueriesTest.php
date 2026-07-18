<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\elements\db\PluginPageQuery;
use lindemannrock\docsmanager\elements\db\SourceDocQuery;
use lindemannrock\docsmanager\tests\TestCase;
use lindemannrock\docsmanager\variables\DocsManagerVariable;

/**
 * Pins the standard element-query Twig surface: `craft.docsManager.sourceDocs()`
 * and `craft.docsManager.pluginPages()` return criteria-configured element
 * queries, matching the suite convention (plural element noun → query).
 *
 * @since 5.38.0
 */
final class TemplateVariableElementQueriesTest extends TestCase
{
    public function testSourceDocsReturnsQuery(): void
    {
        $query = (new DocsManagerVariable())->sourceDocs();

        self::assertInstanceOf(SourceDocQuery::class, $query);
    }

    public function testSourceDocsAppliesCriteria(): void
    {
        $query = (new DocsManagerVariable())->sourceDocs(['limit' => 7]);

        self::assertSame(7, $query->limit);
    }

    public function testPluginPagesReturnsQuery(): void
    {
        $query = (new DocsManagerVariable())->pluginPages();

        self::assertInstanceOf(PluginPageQuery::class, $query);
    }

    public function testPluginPagesAppliesCriteria(): void
    {
        $query = (new DocsManagerVariable())->pluginPages(['limit' => 3]);

        self::assertSame(3, $query->limit);
    }

    public function testSourceDocsQuerySupportsSearch(): void
    {
        // The native-search surface the query exists for: `.search()` must be
        // chainable like any Craft element query.
        $query = (new DocsManagerVariable())->sourceDocs()->search('install');

        self::assertInstanceOf(SourceDocQuery::class, $query);
        self::assertSame('install', $query->search);
    }
}
