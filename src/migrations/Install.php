<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\migrations;

use Craft;
use craft\db\Migration;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\elements\SourceDoc;

/**
 * Installation Migration
 *
 * Creates database tables for storing plugin information and documentation pages.
 *
 * @since 5.0.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Create sources table
        $this->createSourcesTable();

        // Create doc pages table (element-linked)
        $this->createDocPagesTable();

        // Create doc pages content table (translatable data)
        $this->createDocPagesContentTable();

        // Create custom pages table (element-linked, CP-editable)
        $this->createCustomPagesTable();

        // Create settings table
        $this->createSettingsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Delete all elements first (cleans up elements + elements_sites rows)
        $this->delete('{{%elements}}', ['type' => SourceDoc::class]);
        $this->delete('{{%elements}}', ['type' => PluginPage::class]);

        // Drop tables in reverse order
        $this->dropTableIfExists('{{%docsmanager_custom_pages}}');
        $this->dropTableIfExists('{{%docsmanager_pages_content}}');
        $this->dropTableIfExists('{{%docsmanager_pages}}');
        $this->dropTableIfExists('{{%docsmanager_sources}}');
        $this->dropTableIfExists('{{%docsmanager_settings}}');

        return true;
    }

    /**
     * Create sources table
     */
    protected function createSourcesTable(): void
    {
        $this->createTable('{{%docsmanager_sources}}', [
            'id' => $this->primaryKey(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),

            // Source Information
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'iconSvg' => $this->mediumText()->null(),
            'description' => $this->text()->null(),
            'kind' => $this->string(20)->notNull()->defaultValue('plugin'),

            // Source Configuration
            'sourceType' => $this->string(20)->notNull()->defaultValue('local'), // local, github-api
            'repositoryUrl' => $this->string()->null(),
            'localPath' => $this->string()->null(),

            // Version Information (auto-updated on sync)
            'currentVersion' => $this->string(50)->null(),
            'releaseDate' => $this->dateTime()->null(),

            // Status
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'lastSyncedAt' => $this->dateTime()->null(),

            // Changelog (raw markdown, synced from local or GitHub)
            'changelogContent' => $this->mediumText()->null(),

            // Metadata (JSON)
            'metadata' => $this->text()->null(),
        ]);

        // Indexes
        $this->createIndex(null, '{{%docsmanager_sources}}', 'handle', true); // Unique
        $this->createIndex(null, '{{%docsmanager_sources}}', 'enabled', false);
        $this->createIndex(null, '{{%docsmanager_sources}}', 'kind', false);
    }

    /**
     * Create doc pages table (element-linked, non-translatable data)
     */
    protected function createDocPagesTable(): void
    {
        $this->createTable('{{%docsmanager_pages}}', [
            'id' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),

            // Relationships
            'sourceId' => $this->integer()->notNull(),

            // Non-translatable page data
            'category' => $this->string(100)->notNull(), // get-started, features, etc.
            'slug' => $this->string()->notNull(),
            'order' => $this->integer()->notNull()->defaultValue(0),

            'PRIMARY KEY(id)',
        ]);

        // Foreign key to elements table (CASCADE delete)
        $this->addForeignKey(
            null,
            '{{%docsmanager_pages}}',
            'id',
            '{{%elements}}',
            'id',
            'CASCADE'
        );

        // Foreign key to sources table
        $this->addForeignKey(
            null,
            '{{%docsmanager_pages}}',
            'sourceId',
            '{{%docsmanager_sources}}',
            'id',
            'CASCADE'
        );

        // Indexes
        $this->createIndex(null, '{{%docsmanager_pages}}', 'sourceId', false);
        $this->createIndex(null, '{{%docsmanager_pages}}', 'category', false);
        $this->createIndex(null, '{{%docsmanager_pages}}', 'slug', false);
        $this->createIndex(null, '{{%docsmanager_pages}}', ['sourceId', 'slug'], true); // Unique per source
        $this->createIndex(null, '{{%docsmanager_pages}}', ['sourceId', 'category', 'order'], false);
    }

    /**
     * Create doc pages content table (translatable data, one row per page per site)
     */
    protected function createDocPagesContentTable(): void
    {
        $this->createTable('{{%docsmanager_pages_content}}', [
            'id' => $this->primaryKey(),
            'pageId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),

            // Translatable page data
            'title' => $this->string()->notNull(),
            'description' => $this->text()->null(),
            'markdownSource' => $this->mediumText()->notNull(),
            'htmlContent' => $this->mediumText()->notNull(),
            'headings' => $this->text()->null(), // JSON array of H2/H3 headings
            'keywords' => $this->text()->null(), // JSON array
            'lastSyncedAt' => $this->dateTime()->null(),
            'metadata' => $this->text()->null(), // JSON for additional frontmatter data
        ]);

        // Unique constraint: one content row per page per site
        $this->createIndex(null, '{{%docsmanager_pages_content}}', ['pageId', 'siteId'], true);
        $this->createIndex(null, '{{%docsmanager_pages_content}}', 'siteId', false);

        // Foreign keys
        $this->addForeignKey(
            null,
            '{{%docsmanager_pages_content}}',
            'pageId',
            '{{%docsmanager_pages}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%docsmanager_pages_content}}',
            'siteId',
            '{{%sites}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * Create custom pages table (element-linked, CP-editable pages with field layout)
     */
    protected function createCustomPagesTable(): void
    {
        $this->createTable('{{%docsmanager_custom_pages}}', [
            'id' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),

            // Relationships
            'sourceId' => $this->integer()->notNull(),

            // Page data
            'pageType' => $this->string(50)->notNull(),
            'slug' => $this->string()->notNull(),
            'order' => $this->integer()->notNull()->defaultValue(0),

            'PRIMARY KEY(id)',
        ]);

        // Foreign key to elements table (CASCADE delete)
        $this->addForeignKey(
            null,
            '{{%docsmanager_custom_pages}}',
            'id',
            '{{%elements}}',
            'id',
            'CASCADE'
        );

        // Foreign key to sources table
        $this->addForeignKey(
            null,
            '{{%docsmanager_custom_pages}}',
            'sourceId',
            '{{%docsmanager_sources}}',
            'id',
            'CASCADE'
        );

        // Indexes
        $this->createIndex(null, '{{%docsmanager_custom_pages}}', 'sourceId', false);
        $this->createIndex(null, '{{%docsmanager_custom_pages}}', 'pageType', false);
        $this->createIndex(null, '{{%docsmanager_custom_pages}}', ['sourceId', 'pageType'], true);
    }

    /**
     * Create settings table
     */
    protected function createSettingsTable(): void
    {
        $this->createTable('{{%docsmanager_settings}}', [
            'id' => $this->primaryKey(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),

            // General Settings
            'pluginName' => $this->string()->notNull()->defaultValue('Docs Manager'),

            // Site Settings
            'enabledSites' => $this->text()->null()->comment('JSON array of enabled site IDs'),

            'logLevel' => $this->string(20)->notNull()->defaultValue('error'),

            // Source Settings (defaults for new plugins)
            'defaultSourceType' => $this->string(20)->notNull()->defaultValue('local'), // local, github-api
            'localPluginBasePath' => $this->string()->null(),
            'githubToken' => $this->string()->null(),

            // Sync Settings
            'autoSync' => $this->boolean()->notNull()->defaultValue(false),
            'syncSchedule' => $this->string(20)->notNull()->defaultValue('daily'), // hourly, daily, weekly, monthly

            // Parser Settings
            'enableSyntaxHighlighting' => $this->boolean()->notNull()->defaultValue(true),
            'enableAnchorGeneration' => $this->boolean()->notNull()->defaultValue(true),

            // Code Highlighting Settings (for craft-code-highlighter integration)
            'codeTheme' => $this->string(50)->notNull()->defaultValue('tomorrow'),
            'codeFontSize' => $this->integer()->notNull()->defaultValue(14),
            'codeFontFamily' => $this->string()->null(),
            'codeEnableCopyButton' => $this->boolean()->notNull()->defaultValue(true),
            'codeShowLineNumbers' => $this->boolean()->notNull()->defaultValue(true),

            // Display Settings
            'itemsPerPage' => $this->integer()->notNull()->defaultValue(50),
        ]);

        // Insert default row
        $this->insert('{{%docsmanager_settings}}', [
            'id' => 1,
            'pluginName' => 'Docs Manager',
            'logLevel' => 'error',
            'defaultSourceType' => 'local',
            'localPluginBasePath' => '@root/plugins',
            'autoSync' => false,
            'syncSchedule' => 'daily',
            'enableSyntaxHighlighting' => true,
            'enableAnchorGeneration' => true,
            'codeTheme' => 'tomorrow',
            'codeFontSize' => 14,
            'codeFontFamily' => null,
            'codeEnableCopyButton' => true,
            'codeShowLineNumbers' => true,
            'itemsPerPage' => 50,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => \Craft::$app->getSecurity()->generateRandomString(36),
        ]);
    }
}
