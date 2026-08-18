<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Support;

use lindemannrock\docsmanager\tests\TestCase;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Guarantees isolation cleanup even if child teardown fails early.
 *
 * @since 5.4.0
 */
final class TestCleanupExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new TestFinishedCleanupSubscriber());
    }
}

/**
 * Runs the Docs Manager isolation fallback after each finished test.
 *
 * @internal
 * @since 5.4.0
 */
final class TestFinishedCleanupSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        TestCase::finishActiveTestIsolation();
    }
}
