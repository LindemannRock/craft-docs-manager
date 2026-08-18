<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use DateTime;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\jobs\SyncAllPluginsJob;
use lindemannrock\docsmanager\models\Settings;
use yii\db\Expression;

/**
 * Owns scheduled-sync eligibility, identity, and recurring queue lifecycle.
 *
 * @since 5.4.0
 */
final class ScheduledSyncScheduler extends Component
{
    public const PLUGIN_TOKEN = 'docsmanager';
    public const RECURRING_OWNER = 'docs-manager:sync:scheduled';
    public const LIFECYCLE_MUTEX = 'docs-manager:sync:schedule';
    public const PORTABLE_MUTEX = 'docs-manager:sync:portable';

    private const MUTEX_TIMEOUT = 5;

    /** Synchronize the recurring family during plugin bootstrap. */
    public function synchronize(?Settings $settings = null): void
    {
        $settings ??= DocsManager::$plugin->getSettings();
        $nextRun = $this->getNextRun($settings);

        $this->withQueueMutationLocks(
            fn() => $nextRun === null
                ? $this->cancelLocked()
                : $this->queueAtLocked($settings, $nextRun, true),
        );
    }

    /** Replace the complete recurring family after an effective settings change. */
    public function replace(Settings $settings): void
    {
        $nextRun = $this->getNextRun($settings);

        $this->withQueueMutationLocks(function() use ($settings, $nextRun): void {
            $this->cancelLocked();

            if ($nextRun !== null) {
                $this->pushAtLocked($settings, $nextRun);
            }
        });
    }

    /**
     * Replace the recurring family only when its effective policy changed.
     *
     * @param array{enabled: bool, schedule: string} $previousState
     */
    public function replaceIfChanged(Settings $settings, array $previousState): bool
    {
        if ($previousState === $this->getEffectiveState($settings)) {
            return false;
        }

        $this->replace($settings);

        return true;
    }

    /**
     * Run one eligible recurring occurrence and queue its successor on completion.
     *
     * @param callable(): void $sync
     * @return bool Whether the recurring synchronization callback ran
     */
    public function runOccurrence(callable $sync): bool
    {
        return $this->withLifecycleLock(function() use ($sync): bool {
            $settings = DocsManager::$plugin->getSettings();
            if (!$this->isEligible($settings)) {
                return false;
            }

            $sync();
            $nextRun = $this->getNextRun($settings);
            if ($nextRun !== null) {
                $this->withPortableLock(fn() => $this->queueAtLocked($settings, $nextRun, true));
            }

            return true;
        });
    }

    /**
     * Resolve the effective scheduling state used for settings comparisons.
     *
     * @return array{enabled: bool, schedule: string}
     */
    public function getEffectiveState(Settings $settings): array
    {
        return [
            'enabled' => $settings->autoSync,
            'schedule' => $settings->autoSync ? $settings->syncSchedule : 'disabled',
        ];
    }

    /** Resolve the next scheduled wall-clock occurrence. */
    public function getNextRun(?Settings $settings = null, ?DateTime $from = null): ?DateTime
    {
        $settings ??= DocsManager::$plugin->getSettings();
        if (!$this->isEligible($settings)) {
            return null;
        }

        return ScheduleHelper::calculateNext($settings->syncSchedule, $from);
    }

    /** Format the next scheduled occurrence for Craft's queue UI. */
    public function getNextRunTime(?Settings $settings = null, ?DateTime $from = null): ?string
    {
        $settings ??= DocsManager::$plugin->getSettings();
        $nextRun = $this->getNextRun($settings, $from);
        if ($nextRun === null) {
            return null;
        }

        return DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'docs-manager',
        );
    }

    private function isEligible(Settings $settings): bool
    {
        return $settings->autoSync && ScheduleHelper::calculateNext($settings->syncSchedule) !== null;
    }

    private function queueAtLocked(Settings $settings, DateTime $nextRun, bool $preserveHealthyLegacy): void
    {
        if ($preserveHealthyLegacy && $this->retainEarliestHealthyLegacy()) {
            $this->cancelNewOwnerLocked();
            return;
        }

        if ($this->retainEarliestHealthyOwner()) {
            return;
        }

        $this->pushAtLocked($settings, $nextRun);
    }

    private function pushAtLocked(Settings $settings, DateTime $nextRun): void
    {
        $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'docs-manager',
        );

        $jobId = PortableQueueScheduler::pushAt(
            job: new SyncAllPluginsJob([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
                'recurringOwner' => self::RECURRING_OWNER,
            ]),
            targetTimestamp: $nextRun->getTimestamp(),
            identityTokens: [self::PLUGIN_TOKEN, 'SyncAllPluginsJob', self::RECURRING_OWNER],
            mutexName: self::PORTABLE_MUTEX,
            mutexTimeout: self::MUTEX_TIMEOUT,
            priority: 1024,
            ttr: 1800,
        );

        if ($jobId === null) {
            throw new \RuntimeException('Scheduled-sync queue push did not return a job ID.');
        }
    }

    private function retainEarliestHealthyOwner(): bool
    {
        $rows = $this->healthyOwnedRows();
        if ($rows === []) {
            return false;
        }

        if (count($rows) > 1) {
            $this->deleteRows(array_slice($rows, 1));
        }

        return true;
    }

    private function retainEarliestHealthyLegacy(): bool
    {
        $rows = $this->healthyLegacyRows();
        if ($rows === []) {
            return false;
        }

        if (count($rows) > 1) {
            $this->deleteRows(array_slice($rows, 1));
        }

        return true;
    }

    private function cancelLocked(): int
    {
        return $this->cancelNewOwnerLocked() + $this->deleteRows($this->legacyRows());
    }

    private function cancelNewOwnerLocked(): int
    {
        return $this->deleteRows($this->ownedRows());
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function healthyOwnedRows(): array
    {
        /** @var list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}> */
        return $this->ownedQuery()
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->orderBy(new Expression('[[timePushed]] + [[delay]] ASC'))
            ->addOrderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function ownedRows(): array
    {
        /** @var list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}> */
        return $this->ownedQuery()->all();
    }

    private function ownedQuery(): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job', 'timePushed', 'delay', 'priority'])
            ->where(['like', 'job', self::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'SyncAllPluginsJob'])
            ->andWhere(['like', 'job', self::RECURRING_OWNER]);
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function healthyLegacyRows(): array
    {
        $rows = $this->legacyQuery()
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->orderBy(new Expression('[[timePushed]] + [[delay]] ASC'))
            ->addOrderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->filterLegacyRows($rows);
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function legacyRows(): array
    {
        return $this->filterLegacyRows($this->legacyQuery()->all());
    }

    private function legacyQuery(): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job', 'timePushed', 'delay', 'priority'])
            ->where(['like', 'job', self::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'SyncAllPluginsJob'])
            ->andWhere(['not like', 'job', self::RECURRING_OWNER]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function filterLegacyRows(array $rows): array
    {
        $legacyRows = [];
        foreach ($rows as $row) {
            if ($this->isLegacyPayload((string)($row['job'] ?? ''))) {
                /** @var array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string} $row */
                $legacyRows[] = $row;
            }
        }

        return $legacyRows;
    }

    private function isLegacyPayload(string $payload): bool
    {
        if ($payload === '' || str_contains($payload, self::RECURRING_OWNER)) {
            return false;
        }

        $hasExactPluginToken = preg_match('/(?<![A-Za-z0-9_-])docsmanager(?![A-Za-z0-9_-])/', $payload) === 1;
        $hasExactJobClass = preg_match('/(?<![A-Za-z0-9_])SyncAllPluginsJob(?![A-Za-z0-9_])/', $payload) === 1;
        if (!$hasExactPluginToken || !$hasExactJobClass) {
            return false;
        }

        $phpReschedule = str_contains($payload, 's:10:"reschedule";b:1;');
        $jsonReschedule = preg_match('/"reschedule"\s*:\s*true/', $payload) === 1;

        return $phpReschedule || $jsonReschedule;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function deleteRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn(array $row): string => (string)$row['id'], $rows);
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', ['id' => $ids])
            ->execute();

        if ($deleted !== count($ids)) {
            throw new \RuntimeException('Scheduled-sync queue cancellation was incomplete.');
        }

        return $deleted;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLifecycleLock(callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::LIFECYCLE_MUTEX, self::MUTEX_TIMEOUT)) {
            throw new \RuntimeException('Unable to acquire the scheduled-sync lifecycle lock.');
        }

        try {
            return $callback();
        } finally {
            $mutex->release(self::LIFECYCLE_MUTEX);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withPortableLock(callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::PORTABLE_MUTEX, self::MUTEX_TIMEOUT)) {
            throw new \RuntimeException('Unable to acquire the portable scheduled-sync queue lock.');
        }

        try {
            return $callback();
        } finally {
            $mutex->release(self::PORTABLE_MUTEX);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withQueueMutationLocks(callable $callback): mixed
    {
        return $this->withLifecycleLock(fn() => $this->withPortableLock($callback));
    }
}
