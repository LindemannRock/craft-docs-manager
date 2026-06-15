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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\jobs\SyncAllPluginsJob;
use lindemannrock\docsmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Pins Docs Manager's scheduler-pattern integration with base helpers.
 *
 * @since 5.1.0
 */
final class SchedulerPatternTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDocsManagerQueueRows();
    }

    protected function tearDown(): void
    {
        $this->deleteDocsManagerQueueRows();
        parent::tearDown();
    }

    public function testSyncJobReschedulesWhenExistingSyncRowExists(): void
    {
        $settings = DocsManager::$plugin->getSettings();
        $settings->autoSync = true;
        $settings->syncSchedule = 'hourly';

        Craft::$app->getQueue()->delay(300)->push(new SyncAllPluginsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('SyncAllPluginsJob'));

        $job = new SyncAllPluginsJob([
            'reschedule' => true,
        ]);
        $this->invokePrivate($job, 'scheduleNextSync');

        $this->assertSame(2, $this->countQueueRows('SyncAllPluginsJob'));
    }

    public function testBootstrapDoesNotDuplicateExistingDelayedSyncRow(): void
    {
        $settings = DocsManager::$plugin->getSettings();
        $settings->autoSync = true;
        $settings->syncSchedule = 'hourly';

        Craft::$app->getQueue()->delay(300)->push(new SyncAllPluginsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('SyncAllPluginsJob'));

        $this->invokePrivate(DocsManager::$plugin, 'scheduleSyncJob');

        $this->assertSame(1, $this->countQueueRows('SyncAllPluginsJob'));
    }

    public function testBootstrapUsesCanonicalSyncSchedule(): void
    {
        $settings = DocsManager::$plugin->getSettings();
        $settings->autoSync = true;
        $settings->syncSchedule = 'daily';

        $this->invokePrivate(DocsManager::$plugin, 'scheduleSyncJob');

        $row = $this->latestQueueRow('SyncAllPluginsJob');

        self::assertNotNull($row);
        self::assertStringContainsString($this->expectedRunTime('daily'), (string) $row['description']);
    }

    public function testBootstrapCollapsesDuplicatePendingSyncRows(): void
    {
        $settings = DocsManager::$plugin->getSettings();
        $settings->autoSync = true;
        $settings->syncSchedule = 'hourly';

        Craft::$app->getQueue()->delay(300)->push(new SyncAllPluginsJob([
            'reschedule' => true,
        ]));
        Craft::$app->getQueue()->delay(300)->push(new SyncAllPluginsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(2, $this->countQueueRows('SyncAllPluginsJob'));

        $this->invokePrivate(DocsManager::$plugin, 'scheduleSyncJob');

        $this->assertSame(1, $this->countQueueRows('SyncAllPluginsJob'));
    }

    public function testSyncScheduleOptionsUseBaseCuratedList(): void
    {
        $options = DocsManager::$plugin->getSettings()->getSyncScheduleOptions();

        $this->assertSame([
            'hourly',
            'daily',
            'weekly',
            'monthly',
        ], array_column($options, 'value'));
    }

    public function testScheduleChangeHandlerReplacesQueuedSyncJob(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new SyncAllPluginsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('SyncAllPluginsJob'));

        $settings = DocsManager::$plugin->getSettings();
        $settings->autoSync = true;
        $settings->syncSchedule = 'daily';

        DocsManager::$plugin->handleSyncScheduleChange($settings, true, 'weekly');

        $this->assertSame(1, $this->countQueueRows('SyncAllPluginsJob'));
    }

    private function invokePrivate(object $object, string $method): void
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->invoke($object);
    }

    private function countQueueRows(string $jobClass): int
    {
        return (int) (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'docsmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestQueueRow(string $jobClass): ?array
    {
        $row = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'docsmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function expectedRunTime(string $schedule): string
    {
        $nextRun = ScheduleHelper::calculateNext($schedule);
        self::assertNotNull($nextRun);

        return DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            DocsManager::$plugin->getSettings(),
            false,
            false,
        );
    }

    private function deleteDocsManagerQueueRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', [
                'and',
                ['like', 'job', 'docsmanager'],
                ['like', 'job', 'SyncAllPluginsJob'],
            ])
            ->execute();
    }
}
