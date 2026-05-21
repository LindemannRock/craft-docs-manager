<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests;

use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\services\ParserService;

/**
 * Base test case for docs-manager integration tests.
 *
 * Extends the shared {@see IntegrationTestCase} for component snapshot/restore
 * and generic Query helpers, and layers plugin-specific shorthand on top:
 *  - direct accessor for {@see ParserService} (the markdown → HTML pipeline
 *    every plugin's docs site depends on)
 *
 * @since 5.1.0
 */
abstract class TestCase extends IntegrationTestCase
{
    protected ParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = DocsManager::getInstance()->parser;
    }
}
