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
 * Sync Single Plugin Job
 *
 * Syncs a single plugin's documentation (used after adding a new plugin)
 *
 * @author    LindemannRock
 * @package   DocsManager
 * @since     5.0.0
 */
class SyncSinglePluginJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    /**
     * @var string Source handle to sync
     */
    public string $sourceHandle;

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
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Sync the plugin
        $result = DocsManager::getInstance()->sync->syncPlugin($this->sourceHandle);

        if ($result['success']) {
            $this->logInfo("Plugin synced successfully: {$this->sourceHandle}", [
                'pages' => $result['pages'],
                'version' => $result['version'],
            ]);
        } else {
            $this->logError("Failed to sync plugin: {$this->sourceHandle}", [
                'errors' => $result['errors'] ?? [],
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = DocsManager::getInstance()->getSettings();
        return Craft::t('docs-manager', '{pluginName}: Syncing {handle}', [
            'pluginName' => $settings->getFullName(),
            'handle' => $this->sourceHandle,
        ]);
    }
}
