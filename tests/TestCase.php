<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests;

use Craft;
use craft\queue\BaseJob;
use craft\queue\Queue;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\models\Settings;
use lindemannrock\docsmanager\services\ParserService;
use lindemannrock\docsmanager\services\ScheduledSyncScheduler;
use lindemannrock\docsmanager\tests\Support\IsolatedQueue;
use Throwable;

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
    private static ?self $activeTest = null;

    protected ParserService $parser;
    protected ScheduledSyncScheduler $scheduledSync;

    /** @var array<string, mixed>|null */
    private ?array $settingsSnapshot = null;
    /** @var array<string, object> */
    private array $appComponentSnapshots = [];
    private ?object $originalQueue = null;
    private bool $isolationFinished = false;
    private bool $baseStateInitialised = false;

    protected function setUp(): void
    {
        self::$activeTest = $this;
        $this->isolationFinished = false;

        try {
            parent::setUp();
            $this->baseStateInitialised = true;
            $this->snapshotAppComponents();
            $this->settingsSnapshot = DocsManager::getInstance()->getSettings()->getAttributes();
            $this->isolateQueue();
            $this->parser = DocsManager::getInstance()->parser;
            $this->scheduledSync = DocsManager::getInstance()->scheduledSync;
        } catch (Throwable $exception) {
            try {
                $this->finishIsolation();
            } catch (Throwable $cleanupException) {
                fwrite(STDERR, 'Docs Manager setup cleanup failed: ' . $cleanupException->getMessage() . PHP_EOL);
            }
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->finishIsolation();
    }

    /**
     * Runner fallback when child teardown exits before parent cleanup.
     *
     * @since 5.4.0
     */
    public static function finishActiveTestIsolation(): void
    {
        self::$activeTest?->finishIsolation();
    }

    /** Replace a plugin component with exact automatic restoration. */
    protected function replacePluginComponent(string $id, object $component): void
    {
        $this->swapPluginComponent('docs-manager', $id, $component);
    }

    /** Push one job into the connection-local shadow queue. */
    protected function pushOwnedJob(BaseJob $job, int $delay = 0): int
    {
        return (int)Craft::$app->getQueue()->delay($delay)->push($job);
    }

    protected function settings(): Settings
    {
        return DocsManager::getInstance()->getSettings();
    }

    private function snapshotAppComponents(): void
    {
        foreach (['config', 'mutex'] as $id) {
            if (Craft::$app->has($id)) {
                $component = Craft::$app->get($id);
                if (is_object($component)) {
                    $this->appComponentSnapshots[$id] = $component;
                }
            }
        }
    }

    private function isolateQueue(): void
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof IsolatedQueue) {
            throw new \RuntimeException('Docs Manager tests require the bootstrap-isolated Craft queue.');
        }

        $this->originalQueue = $queue;
        $queue->clearShadowRows();

        Craft::$app->set('queue', new Queue([
            'db' => $queue->db,
            'mutex' => $queue->mutex,
            'tableName' => $queue->tableName,
            'channel' => $queue->channel,
            'mutexTimeout' => $queue->mutexTimeout,
        ]));
    }

    private function finishIsolation(): void
    {
        if ($this->isolationFinished) {
            return;
        }
        $this->isolationFinished = true;
        $errors = [];

        $this->runCleanupStep($errors, function(): void {
            foreach ($this->appComponentSnapshots as $id => $component) {
                Craft::$app->set($id, $component);
            }
            $this->appComponentSnapshots = [];
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->settingsSnapshot !== null) {
                DocsManager::getInstance()->getSettings()->setAttributes($this->settingsSnapshot, false);
                $this->settingsSnapshot = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalQueue !== null) {
                Craft::$app->set('queue', $this->originalQueue);
                if ($this->originalQueue instanceof IsolatedQueue) {
                    $this->originalQueue->clearShadowRows();
                }
                $this->originalQueue = null;
            }
        });

        if ($this->baseStateInitialised) {
            $this->runCleanupStep($errors, fn() => parent::tearDown());
            $this->baseStateInitialised = false;
        }
        self::$activeTest = null;

        if ($errors !== []) {
            $messages = array_map(
                static fn(Throwable $error): string => $error::class . ': ' . $error->getMessage(),
                $errors,
            );
            throw new \RuntimeException(
                'Docs Manager test isolation cleanup failed: ' . implode(' | ', $messages),
                0,
                $errors[0],
            );
        }
    }

    /** @param list<Throwable> $errors */
    private function runCleanupStep(array &$errors, callable $cleanup): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            $errors[] = $exception;
        }
    }
}
