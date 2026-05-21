<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Sync All Plugins Job
 *
 * Automatically syncs all enabled plugins on a schedule
 *
 * @author    LindemannRock
 * @package   DocsManager
 * @since     5.0.0
 */
class SyncAllPluginsJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    /**
     * @var bool Whether to reschedule after completion
     */
    public bool $reschedule = false;

    /**
     * @var string|null Next run time display string
     */
    public ?string $nextRunTime = null;

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('docs-manager');

        // Calculate and set next run time if not already set
        if ($this->reschedule && !$this->nextRunTime) {
            $settings = DocsManager::getInstance()->getSettings();
            if ($settings->autoSync) {
                $delay = $this->calculateNextRunDelay($settings->syncSchedule);
                if ($delay > 0) {
                    // Short format: "Nov 8, 12:00am"
                    $this->nextRunTime = date('M j, g:ia', time() + $delay);
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $settings = DocsManager::getInstance()->getSettings();

        // Only run if auto sync is enabled
        if (!$settings->autoSync) {
            return;
        }

        // Sync all enabled plugins
        $results = DocsManager::getInstance()->sync->syncAllPlugins();

        $totalPlugins = count($results);
        $successCount = 0;
        $errorCount = 0;

        foreach ($results as $handle => $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                $errorCount++;
                $this->logWarning("Failed to sync plugin: {$handle}", [
                    'errors' => $result['errors'] ?? [],
                ]);
            }
        }

        $this->logInfo('Scheduled sync completed', [
            'total' => $totalPlugins,
            'success' => $successCount,
            'errors' => $errorCount,
        ]);

        // Reschedule if needed
        if ($this->reschedule) {
            $this->scheduleNextSync();
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = DocsManager::getInstance()->getSettings();
        $description = Craft::t('docs-manager', '{pluginName}: Scheduled plugin sync', [
            'pluginName' => $settings->getFullName(),
        ]);

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }

    /**
     * Schedule the next sync based on settings
     */
    private function scheduleNextSync(): void
    {
        $settings = DocsManager::getInstance()->getSettings();

        // Only reschedule if auto sync is enabled
        if (!$settings->autoSync) {
            return;
        }

        // Prevent duplicate scheduling - check if another sync job already exists
        // This prevents fan-out if multiple jobs end up in the queue (manual runs, retries, etc.)
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'docsmanager'])
            ->andWhere(['like', 'job', 'SyncAllPluginsJob'])
            ->exists();

        if ($existingJob) {
            $this->logDebug('Skipping reschedule - sync job already exists');
            return;
        }

        $delay = $this->calculateNextRunDelay($settings->syncSchedule);

        if ($delay > 0) {
            // Calculate next run time for display
            $nextRunTime = date('M j, g:ia', time() + $delay);

            // Create a new job for the next sync
            $job = new self([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]);

            Craft::$app->getQueue()->delay($delay)->push($job);

            $this->logInfo('Next sync scheduled', [
                'delay_seconds' => $delay,
                'schedule' => $settings->syncSchedule,
                'next_run' => $nextRunTime,
            ]);
        }
    }

    /**
     * Calculate the delay in seconds for the next sync
     */
    private function calculateNextRunDelay(string $schedule): int
    {
        return match ($schedule) {
            'hourly' => 3600, // 1 hour
            'daily' => 86400, // 24 hours
            'weekly' => 604800, // 7 days
            'monthly' => 2592000, // 30 days
            default => 86400, // Default to daily
        };
    }
}
