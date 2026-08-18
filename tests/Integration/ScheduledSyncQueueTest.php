<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use Composer\InstalledVersions;
use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use craft\services\Config;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\DeferredQueueJob;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\docsmanager\jobs\SyncAllPluginsJob;
use lindemannrock\docsmanager\jobs\SyncSinglePluginJob;
use lindemannrock\docsmanager\services\ScheduledSyncScheduler;
use lindemannrock\docsmanager\services\SyncService;
use lindemannrock\docsmanager\tests\Support\IsolatedQueue;
use lindemannrock\docsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use yii\mutex\Mutex;
use yii\queue\sqs\Queue as SqsQueue;

/**
 * Pins Docs Manager's portable recurring synchronization lifecycle.
 *
 * @since 5.4.0
 */
final class ScheduledSyncQueueTest extends TestCase
{
    private const START_TIMESTAMP = 1_800_000_000;

    private ?RecordingSyncSqsQueue $proxyQueue = null;
    private bool $timePaused = false;

    protected function tearDown(): void
    {
        try {
            if ($this->timePaused) {
                DateTimeHelper::resume();
                $this->timePaused = false;
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testQueueStartsAsAnEmptyConnectionLocalShadow(): void
    {
        self::assertSame(0, (int)(new Query())->from('{{%queue}}')->count());
    }

    public function testRunnerFallbackRestoresTheQueueAndRemovesShadowRows(): void
    {
        $this->pushOwnedJob(new SyncAllPluginsJob(['reschedule' => false]));
        self::assertSame(1, (int)(new Query())->from('{{%queue}}')->count());

        self::finishActiveTestIsolation();

        self::assertInstanceOf(IsolatedQueue::class, Craft::$app->getQueue());
        self::assertSame(0, (int)(new Query())->from('{{%queue}}')->count());
    }

    public function testApprovedBaseQueueRuntimeIsAvailableThroughReflection(): void
    {
        $basePath = InstalledVersions::getInstallPath('lindemannrock/craft-plugin-base');
        self::assertIsString($basePath);
        $helper = new ReflectionClass(\lindemannrock\base\helpers\RecurringQueueHelper::class);
        $scheduler = new ReflectionClass(PortableQueueScheduler::class);
        $handoff = new ReflectionClass(DeferredQueueJob::class);

        self::assertTrue($helper->hasMethod('ensurePending'));
        self::assertTrue($helper->hasMethod('deletePending'));
        self::assertTrue($scheduler->hasMethod('pushAt'));
        self::assertTrue($scheduler->hasMethod('continue'));
        self::assertTrue($scheduler->isFinal());
        self::assertTrue($handoff->isFinal());
        self::assertSame(realpath($basePath . '/src/helpers/RecurringQueueHelper.php'), $helper->getFileName());
        self::assertSame(realpath($basePath . '/src/queue/PortableQueueScheduler.php'), $scheduler->getFileName());
        self::assertSame(realpath($basePath . '/src/queue/DeferredQueueJob.php'), $handoff->getFileName());
    }

    public function testScheduleTokensAndLabelsRemainStable(): void
    {
        self::assertSame([
            ['value' => 'hourly', 'label' => 'Hourly'],
            ['value' => 'daily', 'label' => 'Daily'],
            ['value' => 'weekly', 'label' => 'Weekly'],
            ['value' => 'monthly', 'label' => 'Monthly'],
        ], $this->settings()->getSyncScheduleOptions());
    }

    #[DataProvider('scheduleBoundaryProvider')]
    public function testScheduleHelperRetainsCanonicalWallClockTargets(string $schedule, string $from, string $expected): void
    {
        $timezone = new \DateTimeZone(Craft::$app->getTimeZone());
        $next = ScheduleHelper::calculateNext($schedule, new \DateTime($from, $timezone));

        self::assertNotNull($next);
        self::assertSame($expected, $next->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function scheduleBoundaryProvider(): iterable
    {
        $timezone = new \DateTimeZone(Craft::$app->getTimeZone());
        $weekStart = DateRangeHelper::getWeekStartIsoDay();
        $weekly = new \DateTime('2026-08-17 15:45:30', $timezone);
        $days = ($weekStart - (int)$weekly->format('N') + 7) % 7;
        if ($days === 0) {
            $days = 7;
        }
        $weekly->modify("+$days days")->setTime(0, 0, 0);

        yield 'hourly boundary' => ['hourly', '2026-08-17 15:45:30', '2026-08-17 16:00:00'];
        yield 'daily midnight' => ['daily', '2026-08-17 15:45:30', '2026-08-18 00:00:00'];
        yield 'configured weekly boundary' => ['weekly', '2026-08-17 15:45:30', $weekly->format('Y-m-d H:i:s')];
        yield 'monthly same wall clock' => ['monthly', '2026-01-31 15:45:30', '2026-02-28 15:45:30'];
    }

    #[DataProvider('portableBoundaryProvider')]
    public function testSqsDelayBoundaryUsesADeferredHandoffOnlyAboveNineHundredSeconds(int $delay, string $expectedClass): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob('boundary'),
            delay: $delay,
            identityTokens: $this->identityTokens(),
            mutexName: ScheduledSyncScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf($expectedClass, $this->unserializeJob($row));
        self::assertSame($delay <= 900 ? $delay : 900, (int)$row['delay']);
        self::assertSame([$delay <= 900 ? $delay : 900], $this->proxyDelays());
    }

    /** @return iterable<string, array{int, class-string}> */
    public static function portableBoundaryProvider(): iterable
    {
        yield '900 seconds' => [900, SyncAllPluginsJob::class];
        yield '901 seconds' => [901, DeferredQueueJob::class];
    }

    #[DataProvider('longScheduleProvider')]
    public function testLongSchedulesUseMultipleHandoffsWithoutSynchronizingEarly(string $schedule, int $delay): void
    {
        $queue = $this->installPortableQueue(true);
        $sync = new RecordingSyncService();
        $this->replacePluginComponent('sync', $sync);
        $this->pauseAt(self::START_TIMESTAMP);
        $target = self::START_TIMESTAMP + $delay;

        $firstId = PortableQueueScheduler::pushAt(
            job: $this->recurringJob($schedule),
            targetTimestamp: $target,
            identityTokens: $this->identityTokens(),
            mutexName: ScheduledSyncScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        self::assertNotNull($firstId);

        $this->pauseAt(self::START_TIMESTAMP + 900);
        self::assertTrue($queue->executeJob($firstId));
        self::assertInstanceOf(DeferredQueueJob::class, $this->unserializeJob($this->onlyOwnerRow()));
        self::assertSame(0, $sync->calls);

        $secondId = (string)$this->onlyOwnerRow()['id'];
        $this->pauseAt(self::START_TIMESTAMP + 1_800);
        self::assertTrue($queue->executeJob($secondId));
        self::assertInstanceOf(DeferredQueueJob::class, $this->unserializeJob($this->onlyOwnerRow()));
        self::assertSame(0, $sync->calls);

        $thirdId = (string)$this->onlyOwnerRow()['id'];
        $this->pauseAt($target - 300);
        self::assertTrue($queue->executeJob($thirdId));
        $consumerRow = $this->onlyOwnerRow();
        self::assertInstanceOf(SyncAllPluginsJob::class, $this->unserializeJob($consumerRow));
        self::assertSame(300, (int)$consumerRow['delay']);
        self::assertSame(1024, (int)$consumerRow['priority']);
        self::assertSame(1800, (int)$consumerRow['ttr']);
        self::assertSame(0, $sync->calls);
        self::assertLessThanOrEqual(900, max($this->proxyDelays()));
    }

    /** @return iterable<string, array{string, int}> */
    public static function longScheduleProvider(): iterable
    {
        yield 'hourly' => ['hourly', 3_600];
        yield 'daily' => ['daily', 86_400];
        yield 'weekly' => ['weekly', 604_800];
        yield 'monthly' => ['monthly', 2_678_400];
    }

    public function testLateHandoffQueuesTheFinalConsumerWithZeroDelay(): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);
        $jobId = PortableQueueScheduler::push(
            job: $this->recurringJob('late'),
            delay: 901,
            identityTokens: $this->identityTokens(),
            mutexName: ScheduledSyncScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        self::assertNotNull($jobId);

        $this->pauseAt(self::START_TIMESTAMP + 950);
        self::assertTrue($queue->executeJob($jobId));

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf(SyncAllPluginsJob::class, $this->unserializeJob($row));
        self::assertSame(0, (int)$row['delay']);
        self::assertSame([900, 0], $this->proxyDelays());
    }

    public function testNativeQueueRetainsTheCompleteDelay(): void
    {
        $queue = $this->installPortableQueue(false);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob('native'),
            delay: 604_800,
            identityTokens: $this->identityTokens(),
            mutexName: ScheduledSyncScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf(SyncAllPluginsJob::class, $this->unserializeJob($row));
        self::assertSame(604_800, (int)$row['delay']);
        self::assertSame([], $this->proxyDelays());
    }

    public function testRecurringConsumerDescriptionAndQueueContractRemainExact(): void
    {
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'daily';
        $from = new \DateTime('2026-08-17 15:45:30', new \DateTimeZone(Craft::$app->getTimeZone()));
        $nextRunTime = $this->scheduledSync->getNextRunTime($this->settings(), $from);
        self::assertNotNull($nextRunTime);
        $job = $this->recurringJob($nextRunTime);

        self::assertSame(
            $this->settings()->getDisplayName() . ": Scheduled plugin sync ($nextRunTime)",
            $job->getDescription(),
        );
        self::assertFalse($job->canRetry(1, new \RuntimeException('test')));
        self::assertSame(1800, $job->getTtr());

        $rowId = $this->pushOwnedJob($job, 300);
        $row = (new Query())->from('{{%queue}}')->where(['id' => $rowId])->one();
        self::assertIsArray($row);
        $serialized = $this->unserializeJob($row);
        self::assertInstanceOf(SyncAllPluginsJob::class, $serialized);
        self::assertTrue($serialized->reschedule);
        self::assertSame(ScheduledSyncScheduler::RECURRING_OWNER, $serialized->recurringOwner);
        self::assertSame(1024, (int)$row['priority']);
        self::assertSame(1800, (int)$row['ttr']);
    }

    public function testRepeatedBootstrapCreatesExactlyOneOwnerChain(): void
    {
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'daily';

        $this->scheduledSync->synchronize($this->settings());
        $firstId = $this->onlyOwnerRow()['id'];
        $this->scheduledSync->synchronize($this->settings());

        self::assertSame(1, $this->countOwnerRows());
        self::assertSame((string)$firstId, (string)$this->onlyOwnerRow()['id']);
    }

    public function testBootstrapRetainsEarliestHealthyPhpLegacyRowAndRemovesOwnerCompetition(): void
    {
        $this->settings()->autoSync = true;
        $legacyPayload = $this->serializeJob(new SyncAllPluginsJob(['reschedule' => true]));
        $earliestId = $this->insertPayload($legacyPayload, delay: 100);
        $this->insertPayload($legacyPayload, delay: 200);
        $this->pushOwnedJob($this->recurringJob('competing'), 300);

        $this->scheduledSync->synchronize($this->settings());

        self::assertSame([$earliestId], $this->legacyRowIds());
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testJsonAndDeferredWrapperLegacyRowsAreRecognized(): void
    {
        $this->settings()->autoSync = true;
        $jsonId = $this->insertPayload(json_encode([
            'plugin' => 'docsmanager',
            'class' => 'SyncAllPluginsJob',
            'reschedule' => true,
        ], JSON_THROW_ON_ERROR), delay: 100);
        $deferred = new DeferredQueueJob([
            'job' => new SyncAllPluginsJob(['reschedule' => true]),
            'targetTimestamp' => self::START_TIMESTAMP + 2_000,
            'identityTokens' => ['docsmanager', 'SyncAllPluginsJob'],
            'mutexName' => ScheduledSyncScheduler::PORTABLE_MUTEX,
            'chainId' => 'legacy-wrapper-chain',
        ]);
        $this->insertPayload($this->serializeJob($deferred), delay: 200);

        $this->scheduledSync->synchronize($this->settings());

        self::assertSame([$jsonId], $this->legacyRowIds());
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testLegacyRecognitionRequiresExactPluginAndJobTokens(): void
    {
        $preservedIds = [
            $this->insertPayload('{"plugin":"docsmanager-addon","class":"SyncAllPluginsJob","reschedule":true}'),
            $this->insertPayload('{"plugin":"docsmanager","class":"NotSyncAllPluginsJob","reschedule":true}'),
        ];
        $this->settings()->autoSync = true;

        $this->scheduledSync->synchronize($this->settings());

        self::assertSame(1, $this->countOwnerRows());
        self::assertSame($preservedIds, $this->existingIds($preservedIds));
    }

    public function testFailedLegacyRowDoesNotBlockOwnerRecovery(): void
    {
        $this->settings()->autoSync = true;
        $failedId = $this->insertPayload(
            $this->serializeJob(new SyncAllPluginsJob(['reschedule' => true])),
            fail: true,
        );

        $this->scheduledSync->synchronize($this->settings());

        self::assertSame([$failedId], $this->legacyRowIds());
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testReplacementPreservesManualSingleSourceAndUnrelatedRows(): void
    {
        $preservedIds = [
            $this->pushOwnedJob(new SyncAllPluginsJob(['reschedule' => false]), 50),
            $this->pushOwnedJob(new SyncSinglePluginJob(['sourceHandle' => 'example']), 50),
            $this->insertPayload('{"plugin":"docsmanager","class":"OtherDocsJob"}'),
            $this->insertPayload('{"plugin":"another-plugin","class":"SyncAllPluginsJob","reschedule":true}'),
        ];
        $this->settings()->autoSync = false;
        $this->scheduledSync->replace($this->settings());

        self::assertSame(0, $this->countOwnerRows());
        self::assertSame($preservedIds, $this->existingIds($preservedIds));
    }

    public function testReplacementCancelsPendingReservedAndFailedConsumersAndHandoffs(): void
    {
        $ownerPayload = $this->serializeJob($this->recurringJob('owned'));
        $legacyPayload = $this->serializeJob(new SyncAllPluginsJob(['reschedule' => true]));
        foreach ([$ownerPayload, $legacyPayload] as $payload) {
            foreach (['pending', 'reserved', 'failed'] as $state) {
                $this->insertPayload($payload, fail: $state === 'failed', reserved: $state === 'reserved');
            }
        }
        $handoff = new DeferredQueueJob([
            'job' => $this->recurringJob('deferred'),
            'targetTimestamp' => self::START_TIMESTAMP + 2_000,
            'identityTokens' => $this->identityTokens(),
            'mutexName' => ScheduledSyncScheduler::PORTABLE_MUTEX,
            'chainId' => 'owned-deferred-chain',
        ]);
        foreach (['pending', 'reserved', 'failed'] as $state) {
            $this->insertPayload(
                $this->serializeJob($handoff),
                fail: $state === 'failed',
                reserved: $state === 'reserved',
            );
        }

        $this->settings()->autoSync = false;
        $this->scheduledSync->replace($this->settings());

        self::assertSame(0, $this->countOwnerRows());
        self::assertSame([], $this->legacyRowIds());
    }

    public function testCancelledDeferredHandoffCannotResurrectTheSchedule(): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);
        $jobId = PortableQueueScheduler::push(
            job: $this->recurringJob('cancelled'),
            delay: 901,
            identityTokens: $this->identityTokens(),
            mutexName: ScheduledSyncScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        self::assertNotNull($jobId);
        $handoff = $this->unserializeJob($this->onlyOwnerRow());
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        $this->markExecuting($queue, $jobId);

        $this->settings()->autoSync = false;
        $this->scheduledSync->replace($this->settings());
        $handoff->execute($queue);

        self::assertSame(0, $this->countOwnerRows());
        self::assertSame([900], $this->proxyDelays());
        $this->markExecuting($queue, null);
    }

    public function testEffectiveSettingsDisableEnableReplacementAndUnchangedStateAvoidChurn(): void
    {
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'daily';
        $this->scheduledSync->replace($this->settings());
        $firstId = $this->onlyOwnerRow()['id'];
        $dailyState = $this->scheduledSync->getEffectiveState($this->settings());

        self::assertFalse($this->scheduledSync->replaceIfChanged($this->settings(), $dailyState));
        self::assertSame((string)$firstId, (string)$this->onlyOwnerRow()['id']);

        $this->settings()->syncSchedule = 'weekly';
        self::assertTrue($this->scheduledSync->replaceIfChanged($this->settings(), $dailyState));
        $weeklyId = $this->onlyOwnerRow()['id'];
        self::assertNotSame((string)$firstId, (string)$weeklyId);

        $weeklyState = $this->scheduledSync->getEffectiveState($this->settings());
        $this->settings()->autoSync = false;
        self::assertTrue($this->scheduledSync->replaceIfChanged($this->settings(), $weeklyState));
        self::assertSame(0, $this->countOwnerRows());

        $disabledState = $this->scheduledSync->getEffectiveState($this->settings());
        $this->settings()->syncSchedule = 'monthly';
        self::assertFalse($this->scheduledSync->replaceIfChanged($this->settings(), $disabledState));
        $this->settings()->autoSync = true;
        self::assertTrue($this->scheduledSync->replaceIfChanged($this->settings(), $disabledState));
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testSettingsReplacementUsesConfigurationOverrides(): void
    {
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'daily';
        $this->scheduledSync->replace($this->settings());

        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturnCallback(
            static fn(string $handle): array => $handle === 'docs-manager'
                ? ['autoSync' => false, 'syncSchedule' => 'weekly']
                : [],
        );
        Craft::$app->set('config', $config);
        $savedSettings = clone $this->settings();
        PluginHelper::applyConfigOverridesToSettings($savedSettings, 'docs-manager');

        $this->scheduledSync->replaceIfChanged($savedSettings, ['enabled' => true, 'schedule' => 'daily']);

        self::assertFalse($savedSettings->autoSync);
        self::assertSame('weekly', $savedSettings->syncSchedule);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testPortableLockFailurePrecedesInspectionAndLeavesRowsUnchanged(): void
    {
        $payload = $this->serializeJob(new SyncAllPluginsJob(['reschedule' => true]));
        $ids = [$this->insertPayload($payload, delay: 100), $this->insertPayload($payload, delay: 200)];
        $mutex = new SelectiveSyncMutex([ScheduledSyncScheduler::PORTABLE_MUTEX]);
        $originalMutex = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);
        $this->settings()->autoSync = true;

        try {
            $this->scheduledSync->synchronize($this->settings());
            self::fail('Expected portable mutex failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to acquire the portable scheduled-sync queue lock.', $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $originalMutex);
        }

        self::assertSame($ids, $this->legacyRowIds());
        self::assertSame([
            ScheduledSyncScheduler::LIFECYCLE_MUTEX,
            ScheduledSyncScheduler::PORTABLE_MUTEX,
        ], $mutex->acquisitions);
        self::assertSame([ScheduledSyncScheduler::LIFECYCLE_MUTEX], $mutex->releases);
    }

    public function testDisabledBootstrapCancelsUnderLifecycleThenPortableLocks(): void
    {
        $this->pushOwnedJob($this->recurringJob('owned'));
        $mutex = new SelectiveSyncMutex([]);
        $originalMutex = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);
        $this->settings()->autoSync = false;

        try {
            $this->scheduledSync->synchronize($this->settings());
        } finally {
            Craft::$app->set('mutex', $originalMutex);
        }

        self::assertSame(0, $this->countOwnerRows());
        self::assertSame([
            ScheduledSyncScheduler::LIFECYCLE_MUTEX,
            ScheduledSyncScheduler::PORTABLE_MUTEX,
        ], $mutex->acquisitions);
        self::assertSame([
            ScheduledSyncScheduler::PORTABLE_MUTEX,
            ScheduledSyncScheduler::LIFECYCLE_MUTEX,
        ], $mutex->releases);
    }

    public function testDeferredContinuationUsesTheSamePortableMutex(): void
    {
        $queue = $this->installPortableQueue(true);
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'monthly';
        $this->scheduledSync->synchronize($this->settings());
        $row = $this->onlyOwnerRow();
        $handoff = $this->unserializeJob($row);
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame(ScheduledSyncScheduler::PORTABLE_MUTEX, $handoff->mutexName);
        self::assertSame($this->identityTokens(), $handoff->identityTokens);

        $mutex = new SelectiveSyncMutex([]);
        $originalMutex = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);
        $this->markExecuting($queue, (string)$row['id']);
        $this->pauseAt($handoff->targetTimestamp - 900);
        try {
            $handoff->execute($queue);
        } finally {
            $this->markExecuting($queue, null);
            Craft::$app->set('mutex', $originalMutex);
        }

        self::assertSame([ScheduledSyncScheduler::PORTABLE_MUTEX], $mutex->acquisitions);
        self::assertSame([ScheduledSyncScheduler::PORTABLE_MUTEX], $mutex->releases);
    }

    public function testCanonicalTargetRemainsExactWhenPortableLockAcquisitionAdvancesTime(): void
    {
        $this->installPortableQueue(true);
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'weekly';
        $target = $this->scheduledSync->getNextRun($this->settings());
        self::assertNotNull($target);
        $this->pauseAt($target->getTimestamp() - 2_000);
        $mutex = new SelectiveSyncMutex([], $target->getTimestamp() - 1_000);
        $originalMutex = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);
        try {
            $this->scheduledSync->synchronize($this->settings());
        } finally {
            Craft::$app->set('mutex', $originalMutex);
        }

        $handoff = $this->unserializeJob($this->onlyOwnerRow());
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame($target->getTimestamp(), $handoff->targetTimestamp);
    }

    public function testDisabledReservedConsumerDoesNotSynchronizeOrCreateSuccessor(): void
    {
        $sync = new RecordingSyncService();
        $this->replacePluginComponent('sync', $sync);
        $this->settings()->autoSync = false;

        $this->recurringJob('disabled')->execute(Craft::$app->getQueue());

        self::assertSame(0, $sync->calls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testManualConsumerRetainsExistingNonRecurringBehavior(): void
    {
        $sync = new RecordingSyncService();
        $this->replacePluginComponent('sync', $sync);
        $this->settings()->autoSync = true;

        (new SyncAllPluginsJob(['reschedule' => false]))->execute(Craft::$app->getQueue());

        self::assertSame(1, $sync->calls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testCompleteResultCreatesExactlyOneSuccessor(): void
    {
        $sync = new RecordingSyncService();
        $this->replacePluginComponent('sync', $sync);
        $this->settings()->autoSync = true;
        $this->settings()->syncSchedule = 'daily';

        $this->recurringJob('complete')->execute(Craft::$app->getQueue());

        self::assertSame(1, $sync->calls);
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testReturnedPartialFailureLogsCountsAndCreatesOneSuccessor(): void
    {
        $sync = new RecordingSyncService();
        $sync->results = [
            'alpha' => ['success' => true, 'errors' => []],
            'beta' => ['success' => false, 'errors' => ['failed']],
        ];
        $this->replacePluginComponent('sync', $sync);
        $this->settings()->autoSync = true;
        $messageOffset = count(Craft::getLogger()->messages);

        $this->recurringJob('partial')->execute(Craft::$app->getQueue());

        $messages = array_column(array_slice(Craft::getLogger()->messages, $messageOffset), 0);
        self::assertTrue($this->containsMessage($messages, 'Failed to sync plugin: beta'));
        self::assertTrue($this->containsMessage($messages, 'Scheduled sync completed | {"total":2,"success":1,"errors":1}'));
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testThrownSynchronizationFailurePropagatesWithoutSuccessor(): void
    {
        $sync = new RecordingSyncService();
        $sync->failure = new \RuntimeException('sync failed');
        $this->replacePluginComponent('sync', $sync);
        $this->settings()->autoSync = true;

        try {
            $this->recurringJob('failure')->execute(Craft::$app->getQueue());
            self::fail('Expected synchronization failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('sync failed', $exception->getMessage());
        }

        self::assertSame(1, $sync->calls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testLifecycleAndPushFailuresRemainObservable(): void
    {
        $originalMutex = Craft::$app->getMutex();
        Craft::$app->set('mutex', new SelectiveSyncMutex([ScheduledSyncScheduler::LIFECYCLE_MUTEX]));
        $this->settings()->autoSync = true;
        try {
            $this->scheduledSync->synchronize($this->settings());
            self::fail('Expected lifecycle mutex failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to acquire the scheduled-sync lifecycle lock.', $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $originalMutex);
        }

        $this->installPortableQueue(true);
        self::assertNotNull($this->proxyQueue);
        $this->proxyQueue->failPushes = true;
        try {
            $this->scheduledSync->replace($this->settings());
            self::fail('Expected proxy push failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Scheduled sync proxy failure.', $exception->getMessage());
            self::assertSame(1, $this->countOwnerRows());
        }
    }

    public function testCancellationFailurePropagatesWithoutASettingsSuccessPath(): void
    {
        $this->pushOwnedJob($this->recurringJob('owned'));
        $db = Craft::$app->getDb();
        $originalCommandClass = $db->commandClass;
        $db->commandClass = FailingQueueDeleteCommand::class;
        $this->settings()->autoSync = false;

        try {
            $this->scheduledSync->replace($this->settings());
            self::fail('Expected queue cancellation failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Scheduled sync cancellation failure.', $exception->getMessage());
        } finally {
            $db->commandClass = $originalCommandClass;
        }

        self::assertSame(1, $this->countOwnerRows());
    }

    public function testRuntimeHasNoProviderDependencyOrPrivateInfrastructureInspection(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = file_get_contents($root . '/src/services/ScheduledSyncScheduler.php')
            . file_get_contents($root . '/src/jobs/SyncAllPluginsJob.php');
        $composer = file_get_contents($root . '/composer.json');

        self::assertIsString($runtime);
        self::assertIsString($composer);
        self::assertStringNotContainsString('craft\\cloud', $runtime);
        self::assertStringNotContainsString('craftcms/cloud', $composer);
        self::assertStringNotContainsString('AWS_', $runtime);
        self::assertStringNotContainsString('CLOUD_', $runtime);
    }

    private function installPortableQueue(bool $bounded): Queue
    {
        $current = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $current);
        $this->proxyQueue = $bounded ? new RecordingSyncSqsQueue() : null;
        $queue = new Queue([
            'db' => Craft::$app->getDb(),
            'mutex' => Craft::$app->getMutex(),
            'tableName' => $current->tableName,
            'channel' => $current->channel,
            'mutexTimeout' => $current->mutexTimeout,
            'proxyQueue' => $this->proxyQueue,
        ]);
        Craft::$app->set('queue', $queue);

        $installedQueue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $installedQueue);

        return $installedQueue;
    }

    private function pauseAt(int $timestamp): void
    {
        if ($this->timePaused) {
            DateTimeHelper::resume();
        }
        DateTimeHelper::pause(new \DateTime("@$timestamp"));
        $this->timePaused = true;
    }

    private function recurringJob(string $nextRunTime): SyncAllPluginsJob
    {
        return new SyncAllPluginsJob([
            'reschedule' => true,
            'recurringOwner' => ScheduledSyncScheduler::RECURRING_OWNER,
            'nextRunTime' => $nextRunTime,
        ]);
    }

    /** @return non-empty-list<string> */
    private function identityTokens(): array
    {
        return [ScheduledSyncScheduler::PLUGIN_TOKEN, 'SyncAllPluginsJob', ScheduledSyncScheduler::RECURRING_OWNER];
    }

    /** @return array<string, mixed> */
    private function onlyOwnerRow(): array
    {
        $rows = $this->ownerQuery()->orderBy(['id' => SORT_ASC])->all();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function countOwnerRows(): int
    {
        return (int)$this->ownerQuery()->count();
    }

    private function ownerQuery(): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', ScheduledSyncScheduler::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'SyncAllPluginsJob'])
            ->andWhere(['like', 'job', ScheduledSyncScheduler::RECURRING_OWNER]);
    }

    /** @return list<int> */
    private function legacyRowIds(): array
    {
        $ids = [];
        $rows = (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job'])
            ->where(['like', 'job', ScheduledSyncScheduler::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'SyncAllPluginsJob'])
            ->andWhere(['not like', 'job', ScheduledSyncScheduler::RECURRING_OWNER])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($rows as $row) {
            $payload = (string)$row['job'];
            if (str_contains($payload, 's:10:"reschedule";b:1;')
                || preg_match('/"reschedule"\s*:\s*true/', $payload) === 1
            ) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids;
    }

    /** @param list<int> $ids @return list<int> */
    private function existingIds(array $ids): array
    {
        return array_map('intval', (new Query())
            ->from('{{%queue}}')
            ->where(['id' => $ids])
            ->orderBy(['id' => SORT_ASC])
            ->column());
    }

    private function serializeJob(object $job): string
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return $queue->serializer->serialize($job);
    }

    /** @param array<string, mixed> $row */
    private function unserializeJob(array $row): object
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $job = $queue->serializer->unserialize((string)$row['job']);
        self::assertIsObject($job);

        return $job;
    }

    private function insertPayload(string $payload, int $delay = 300, bool $fail = false, bool $reserved = false): int
    {
        Craft::$app->getDb()->createCommand()->insert('{{%queue}}', [
            'channel' => 'queue',
            'job' => $payload,
            'description' => 'Docs Manager queue test row',
            'timePushed' => DateTimeHelper::currentTimeStamp(),
            'ttr' => 1800,
            'delay' => $delay,
            'priority' => 1024,
            'timeUpdated' => $reserved ? DateTimeHelper::currentTimeStamp() : null,
            'fail' => $fail,
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /** @return list<int> */
    private function proxyDelays(): array
    {
        return $this->proxyQueue === null ? [] : array_column($this->proxyQueue->pushes, 'delay');
    }

    private function markExecuting(Queue $queue, ?string $jobId): void
    {
        if ($jobId !== null) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%queue}}', ['timeUpdated' => DateTimeHelper::currentTimeStamp()], ['id' => $jobId])
                ->execute();
        }
        $property = new ReflectionProperty(Queue::class, '_executingJobId');
        $property->setValue($queue, $jobId);
    }

    /** @param list<mixed> $messages */
    private function containsMessage(array $messages, string $expected): bool
    {
        foreach ($messages as $message) {
            if (is_string($message) && str_contains($message, $expected)) {
                return true;
            }
        }

        return false;
    }
}

/** Records bounded proxy pushes without contacting a provider. */
final class RecordingSyncSqsQueue extends SqsQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];
    public bool $failPushes = false;

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        if ($this->failPushes) {
            throw new \RuntimeException('Scheduled sync proxy failure.');
        }
        $this->pushes[] = ['delay' => (int)$delay, 'priority' => $priority, 'ttr' => (int)$ttr];

        return 'scheduled-sync-proxy-' . count($this->pushes);
    }
}

/** Sync seam that returns controlled results without touching sources or pages. */
final class RecordingSyncService extends SyncService
{
    public int $calls = 0;
    /** @var array<string, array{success: bool, errors: list<string>}> */
    public array $results = ['example' => ['success' => true, 'errors' => []]];
    public ?\Throwable $failure = null;

    public function syncAllPlugins(): array
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->results;
    }
}

/** Mutex seam that fails only explicitly named locks. */
final class SelectiveSyncMutex extends Mutex
{
    /** @var list<string> */
    public array $acquisitions = [];
    /** @var list<string> */
    public array $releases = [];

    /** @param list<string> $failedNames */
    public function __construct(
        private readonly array $failedNames,
        private readonly ?int $portableTimestamp = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    protected function acquireLock($name, $timeout = 0): bool
    {
        $this->acquisitions[] = (string)$name;
        if ($name === ScheduledSyncScheduler::PORTABLE_MUTEX && $this->portableTimestamp !== null) {
            DateTimeHelper::resume();
            DateTimeHelper::pause(new \DateTime('@' . $this->portableTimestamp));
        }

        return !in_array($name, $this->failedNames, true);
    }

    protected function releaseLock($name): bool
    {
        $this->releases[] = (string)$name;

        return true;
    }
}

/** Command seam that makes exact queue deletion fail before any row changes. */
final class FailingQueueDeleteCommand extends \craft\db\Command
{
    public function delete($table, $condition = '', $params = [])
    {
        if ($table === '{{%queue}}') {
            throw new \RuntimeException('Scheduled sync cancellation failure.');
        }

        return parent::delete($table, $condition, $params);
    }
}
