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
     * @var string Stable recurring queue owner
     * @since 5.4.0
     */
    public string $recurringOwner = '';

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

        if ($this->reschedule && !$this->nextRunTime && DocsManager::$plugin !== null) {
            $this->nextRunTime = DocsManager::$plugin->scheduledSync->getNextRunTime();
        }
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if ($this->reschedule) {
            DocsManager::$plugin->scheduledSync->runOccurrence(fn() => $this->syncAndLog());
            return;
        }

        $settings = DocsManager::getInstance()->getSettings();

        // Only run if auto sync is enabled
        if (!$settings->autoSync) {
            return;
        }

        $this->syncAndLog();
    }

    /** Sync every enabled source and retain the existing result accounting. */
    private function syncAndLog(): void
    {
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
}
