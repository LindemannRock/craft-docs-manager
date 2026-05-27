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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
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

        if ($this->reschedule && !$this->nextRunTime) {
            $settings = DocsManager::getInstance()->getSettings();
            if ($settings->autoSync) {
                $nextRun = ScheduleHelper::calculateNext($settings->syncSchedule);
                if ($nextRun !== null) {
                    $this->nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
                        $nextRun,
                        $settings,
                        false,
                        false,
                    );
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
            'pluginName' => $settings->getDisplayName(),
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

        $nextRun = ScheduleHelper::calculateNext($settings->syncSchedule);

        if ($nextRun !== null) {
            $delay = max(0, $nextRun->getTimestamp() - DateFormatHelper::now()->getTimestamp());
            $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
                $nextRun,
                $settings,
                false,
                false,
            );
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
}
