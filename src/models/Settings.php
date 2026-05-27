<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\traits\DateFormatSettingsTrait;
use lindemannrock\base\traits\ItemsPerPageSettingsTrait;
use lindemannrock\base\traits\LogLevelSettingsTrait;
use lindemannrock\base\traits\PluginNameSettingsTrait;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\codehighlighter\traits\CodeHighlighterTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Settings Model for Docs Manager
 *
 * @since 5.0.0
 */
class Settings extends Model
{
    use CodeHighlighterTrait;
    use DateFormatSettingsTrait;
    use ItemsPerPageSettingsTrait;
    use LogLevelSettingsTrait;
    use LoggingTrait;
    use PluginNameSettingsTrait;
    use SettingsConfigTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;

    /**
     * Sync schedule options exposed by Docs Manager.
     */
    private const SYNC_SCHEDULE_OPTIONS = [
        'hourly',
        'daily',
        'weekly',
        'monthly',
    ];

    /**
     * @var string Plugin display name
     */
    public string $pluginName = 'Docs Manager';

    // Source Settings (Defaults for new plugins)
    /**
     * @var string Default source type (local, github-api)
     */
    public string $defaultSourceType = 'local';

    /**
     * @var string|null Base path for local plugin docs
     */
    public ?string $localPluginBasePath = '@root/plugins';

    /**
     * @var string|null GitHub token for API access
     */
    public ?string $githubToken = null;

    // Sync Settings
    /**
     * @var bool Enable automatic sync runs
     */
    public bool $autoSync = false;

    /**
     * @var string Sync schedule (hourly, daily, weekly, monthly)
     */
    public string $syncSchedule = 'daily';

    // Parser Settings
    /**
     * @var bool Enable Prism-based syntax highlighting in docs
     */
    public bool $enableSyntaxHighlighting = true;

    /**
     * @var bool Generate anchor links for headings
     */
    public bool $enableAnchorGeneration = true;

    // Code Highlighting Settings (for craft-code-highlighter integration)
    /**
     * @var string Default code theme slug
     */
    public string $codeTheme = 'tomorrow';

    /**
     * @var int Default code font size in pixels
     */
    public int $codeFontSize = 14;

    /**
     * @var string|null Default code font stack
     */
    public ?string $codeFontFamily = null;

    /**
     * @var bool Show copy button on code blocks
     */
    public bool $codeEnableCopyButton = true;

    /**
     * @var bool Show line numbers on code blocks
     */
    public bool $codeShowLineNumbers = true;

    // Site Settings
    /**
     * @var array Site IDs where Docs Manager should be enabled
     */
    public array $enabledSites = [];

    /**
     * Initialize the settings model
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('docs-manager');
    }

    /**
     * Define behaviors for environment variable parsing
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'localPluginBasePath',
                    'githubToken',
                ],
            ],
        ];
    }

    /**
     * Define validation rules
     */
    protected function defineRules(): array
    {
        return array_merge([
            [['defaultSourceType', 'localPluginBasePath', 'githubToken', 'syncSchedule'], 'string'],
            [['defaultSourceType'], 'in', 'range' => ['local', 'github-api']],
            [['syncSchedule'], 'in', 'range' => ScheduleHelper::getValidValues(self::SYNC_SCHEDULE_OPTIONS)],
            [['autoSync', 'enableSyntaxHighlighting', 'enableAnchorGeneration', 'codeEnableCopyButton', 'codeShowLineNumbers'], 'boolean'],
            [['codeTheme'], 'string', 'max' => 50],
            [['codeFontSize'], 'integer', 'min' => 8, 'max' => 32],
            [['codeFontFamily'], 'string', 'max' => 255],
            [['enabledSites'], 'each', 'rule' => ['integer']],
            [['enabledSites'], 'validateEnabledSites'],
            [['localPluginBasePath'], 'required', 'when' => fn(self $model): bool => $model->defaultSourceType === 'local'],
            [['localPluginBasePath'], 'validateLocalPluginBasePath'],
        ], $this->pluginNameSettingsRules(), $this->logLevelSettingsRules(), $this->dateFormatSettingsRules(), $this->itemsPerPageSettingsRules());
    }

    /**
     * Get sync schedule options for settings dropdowns.
     *
     * @return array<array{value: string, label: string}>
     * @since 5.1.0
     */
    public function getSyncScheduleOptions(): array
    {
        return ScheduleHelper::getOptions(self::SYNC_SCHEDULE_OPTIONS);
    }

    public function attributeLabels(): array
    {
        return array_merge([
            'enabledSites' => Craft::t('docs-manager', 'Enabled Sites'),
            'defaultSourceType' => Craft::t('docs-manager', 'Default Source Type'),
            'localPluginBasePath' => Craft::t('docs-manager', 'Local Plugin Base Path'),
            'githubToken' => Craft::t('docs-manager', 'GitHub Token'),
            'autoSync' => Craft::t('docs-manager', 'Auto Sync'),
            'syncSchedule' => Craft::t('docs-manager', 'Sync Schedule'),
            'enableSyntaxHighlighting' => Craft::t('docs-manager', 'Enable Syntax Highlighting'),
            'codeTheme' => Craft::t('docs-manager', 'Code Theme'),
            'codeFontSize' => Craft::t('docs-manager', 'Font Size'),
            'codeFontFamily' => Craft::t('docs-manager', 'Font Family (Optional)'),
            'codeShowLineNumbers' => Craft::t('docs-manager', 'Show Line Numbers'),
            'codeEnableCopyButton' => Craft::t('docs-manager', 'Enable Copy Button'),
            'enableAnchorGeneration' => Craft::t('docs-manager', 'Enable Anchor Generation'),
        ],
            $this->pluginNameSettingsLabel(),
            $this->logLevelSettingsLabel(),
            $this->dateFormatSettingsLabels(),
            $this->itemsPerPageSettingsLabel(),
        );
    }

    /**
     * Get available code themes from code-highlighter plugin
     */
    public function getAvailableCodeThemes(): array
    {
        $themes = $this->getAvailableThemes();

        if (!empty($themes)) {
            return $themes;
        }

        // Minimal fallback when code-highlighter is not installed
        return ['default' => 'Default'];
    }

    public function validateEnabledSites(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_array($value) || $value === []) {
            return;
        }

        $validSiteIds = array_map(static fn($site): int => (int) $site->id, Craft::$app->getSites()->getAllSites());
        foreach ($value as $siteId) {
            if (!in_array((int) $siteId, $validSiteIds, true)) {
                $this->addError($attribute, Craft::t('docs-manager', 'One or more selected sites are invalid.'));
                return;
            }
        }
    }

    public function validateLocalPluginBasePath(string $attribute): void
    {
        $value = trim((string) $this->$attribute);
        if ($value === '') {
            return;
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $value) === 1 || str_contains($value, "\0")) {
            $this->addError($attribute, Craft::t('docs-manager', 'Local plugin base path contains invalid path traversal.'));
            return;
        }

        $parsed = App::parseEnv($value);
        if (!is_string($parsed) || trim($parsed) === '') {
            $this->addError($attribute, Craft::t('docs-manager', 'Local plugin base path is invalid.'));
            return;
        }

        $resolved = trim($parsed);
        if (str_starts_with($resolved, '@')) {
            try {
                $resolved = trim(Craft::getAlias($resolved));
            } catch (\Throwable) {
                $this->addError($attribute, Craft::t('docs-manager', 'Local plugin base path alias could not be resolved.'));
                return;
            }
        }

        if ($resolved === '') {
            $this->addError($attribute, Craft::t('docs-manager', 'Local plugin base path is invalid.'));
            return;
        }

        if (
            !str_starts_with($resolved, '/')
            && preg_match('/^[A-Za-z]:[\\\\\\/]/', $resolved) !== 1
        ) {
            $this->addError($attribute, Craft::t('docs-manager', 'Local plugin base path must be an absolute path or valid alias.'));
            return;
        }

        if (!is_dir($resolved)) {
            $this->addError($attribute, Craft::t('docs-manager', 'Local plugin base path does not exist: {path}', ['path' => $resolved]));
        }
    }

    // =========================================================================
    // Site Methods
    // =========================================================================

    /**
     * Check if a site is enabled for Docs Manager
     *
     * @param int $siteId
     * @return bool
     */
    public function isSiteEnabled(int $siteId): bool
    {
        if (empty($this->enabledSites)) {
            return true;
        }

        return in_array($siteId, $this->enabledSites);
    }

    /**
     * Get enabled site IDs, defaulting to all sites if none specified
     *
     * @return array
     */
    public function getEnabledSiteIds(): array
    {
        if (empty($this->enabledSites)) {
            return array_map(fn($site) => $site->id, Craft::$app->getSites()->getAllSites());
        }

        return $this->enabledSites;
    }

    // =========================================================================
    // Trait Configuration Methods
    // =========================================================================

    /**
     * Database table name for settings storage
     */
    protected static function tableName(): string
    {
        return 'docsmanager_settings';
    }

    /**
     * Plugin handle for config file resolution
     */
    protected static function pluginHandle(): string
    {
        return 'docs-manager';
    }

    /**
     * Fields that should be cast to boolean
     */
    protected static function booleanFields(): array
    {
        return [
            'autoSync',
            'enableSyntaxHighlighting',
            'enableAnchorGeneration',
            'codeEnableCopyButton',
            'codeShowLineNumbers',
            'showSeconds',
        ];
    }

    /**
     * Fields that should be cast to integer
     */
    protected static function integerFields(): array
    {
        return [
            'itemsPerPage',
            'codeFontSize',
        ];
    }

    /**
     * Fields that should be JSON encoded/decoded
     */
    protected static function jsonFields(): array
    {
        return [
            'enabledSites',
        ];
    }

    /**
     * Fields to exclude from database save
     */
    protected static function excludeFromSave(): array
    {
        return [];
    }
}
