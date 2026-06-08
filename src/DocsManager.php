<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * Parse and sync documentation from markdown files to Craft CMS
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\models\Settings;
use lindemannrock\docsmanager\services\ChangelogService;
use lindemannrock\docsmanager\services\CodeExtractorService;
use lindemannrock\docsmanager\services\DocsGeneratorService;
use lindemannrock\docsmanager\services\ParserService;
use lindemannrock\docsmanager\services\ReadmeParserService;
use lindemannrock\docsmanager\services\SyncService;
use lindemannrock\docsmanager\services\VersionService;
use lindemannrock\docsmanager\variables\DocsManagerVariable;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\base\Event;

/**
 * Docs Manager
 *
 * Main plugin class for Docs Manager.
 *
 * @author    LindemannRock
 * @package   DocsManager
 * @since     5.0.0
 *
 * @property-read SyncService $sync
 * @property-read ParserService $parser
 * @property-read VersionService $versionDetector
 * @property-read ChangelogService $changelog
 * @property-read CodeExtractorService $codeExtractor
 * @property-read DocsGeneratorService $docsGenerator
 * @property-read ReadmeParserService $readmeParser
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class DocsManager extends BasePlugin
{
    use LoggingTrait;

    /**
     * @var DocsManager|null Singleton plugin instance
     */
    public static ?DocsManager $plugin = null;

    /**
     * @var string Schema version for migrations
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool Whether the plugin exposes a control panel settings page
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool Whether the plugin settings page is accessible when allowAdminChanges is false
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @var bool Whether the plugin registers its own control panel section
     */
    public bool $hasCpSection = true;

    // =========================================================================
    // PLUGIN CONFIGURATION
    // =========================================================================

    public static function config(): array
    {
        return [
            'components' => [
                'sync' => SyncService::class,
                'parser' => ParserService::class,
                'versionDetector' => VersionService::class,
                'changelog' => ChangelogService::class,
                'codeExtractor' => CodeExtractorService::class,
                'docsGenerator' => DocsGeneratorService::class,
                'readmeParser' => ReadmeParserService::class,
            ],
        ];
    }

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Bootstrap base module (logging + Twig extension)
        PluginHelper::bootstrap(
            $this,
            'docsManagerHelper',
            ['docsManager:viewSystemLogs'],
            ['docsManager:downloadSystemLogs'],
            [
                'colorSets' => [
                    'pageType' => [
                        'features' => ColorHelper::getPaletteColor('indigo'),
                        'faq' => ColorHelper::getPaletteColor('cyan'),
                        'support' => ColorHelper::getPaletteColor('rose'),
                        'pricing' => ColorHelper::getPaletteColor('violet'),
                        'custom' => ColorHelper::getPaletteColor('lime'),
                    ],
                    'sourceKind' => [
                        'plugin' => ColorHelper::getPaletteColor('blue'),
                        'theme' => ColorHelper::getPaletteColor('purple'),
                    ],
                ],
            ]
        );
        PluginHelper::applyPluginNameFromConfig($this);

        // Register Template Roots
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['docs-manager'] = __DIR__ . '/templates';
            }
        );

        // Register Twig Extension
        Craft::$app->view->registerTwigExtension(new \lindemannrock\docsmanager\twigextensions\DocsManagerTwigExtension());

        // Register element types
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = SourceDoc::class;
                $event->types[] = PluginPage::class;
            }
        );

        // Register CP Routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, $this->getCpUrlRules());
            }
        );

        // Register Site Routes — docs image serving
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['plugins/<handle:[a-z0-9\-]+>/docs/images/<path:.+>']
                    = 'docs-manager/images/serve';
            }
        );

        // Register Variables — accessible in templates via craft.docsManager
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('docsManager', DocsManagerVariable::class);
            }
        );

        // Register Permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $settings = $this->getSettings();
                $event->permissions[] = [
                    'heading' => $settings->getFullName(),
                    'permissions' => $this->getPluginPermissions(),
                ];
            }
        );

        // Register Cache Options
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                $settings = $this->getSettings();
                $pluginName = $settings->getFullName();

                $event->options[] = [
                    'key' => 'docs-manager-cache',
                    'label' => Craft::t('docs-manager', '{pluginName} caches', ['pluginName' => $pluginName]),
                    'action' => function() {
                        $this->logInfo('Cleared Docs Manager cache');
                    },
                ];
            }
        );

        // Register Console Controllers
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lindemannrock\docsmanager\console\controllers';
        }

        // Schedule Background Jobs
        $this->scheduleSyncJob();
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Get sites where Docs Manager is enabled, filtered by user's editable sites
     *
     * @return array
     */
    public function getEnabledSites(): array
    {
        $settings = $this->getSettings();
        $enabledSiteIds = $settings->getEnabledSiteIds();
        $editableSiteIds = Craft::$app->getIsMultiSite()
            ? Craft::$app->getSites()->getEditableSiteIds()
            : null;

        return array_filter(Craft::$app->getSites()->getAllSites(), function($site) use ($enabledSiteIds, $editableSiteIds) {
            if (!in_array($site->id, $enabledSiteIds)) {
                return false;
            }
            if ($editableSiteIds !== null && !in_array($site->id, $editableSiteIds)) {
                return false;
            }
            return true;
        });
    }

    /**
     * @inheritdoc
     */
    public function setSettings(array|Model $settings): void
    {
        // No-op: settings come from loadFromDatabase() in createSettingsModel()
    }

    protected function createSettingsModel(): ?Model
    {
        try {
            return Settings::loadFromDatabase();
        } catch (\Exception $e) {
            return new Settings();
        }
    }

    public function getSettings(): ?Model
    {
        $settings = parent::getSettings();

        if ($settings) {
            PluginHelper::applyConfigOverridesToSettings($settings, 'docs-manager');
        }

        return $settings;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('docs-manager/settings');
    }

    /**
     * @inheritdoc
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('docs-manager/settings');
    }

    // =========================================================================
    // CP NAVIGATION
    // =========================================================================

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $settings = $this->getSettings();
        $user = Craft::$app->getUser();

        if ($item) {
            $item['label'] = $settings->getFullName();

            $sections = $this->getCpSections();
            $item['subnav'] = CpNavHelper::buildSubnav($user, $settings, $sections);

            // Add logs section using the logging library
            if (PluginHelper::isPluginEnabled('logging-library')) {
                $item = LoggingLibrary::addLogsNav($item, $this->handle, [
                    'docsManager:viewSystemLogs',
                ]);
            }

            // Hide from nav if no accessible subnav items
            if (empty($item['subnav'])) {
                return null;
            }
        }

        return $item;
    }

    /**
     * Get CP sections for nav + default route resolution
     *
     * @return array
     * @since 5.1.0
     */
    public function getCpSections(): array
    {
        return [
            [
                'key' => 'sources',
                'label' => Craft::t('docs-manager', 'Sources'),
                'url' => 'docs-manager/sources',
                'permissionsAll' => ['docsManager:manageSources'],
            ],
            [
                'key' => 'pages',
                'label' => Craft::t('docs-manager', 'Pages'),
                'url' => 'docs-manager/pages',
                'permissionsAll' => ['docsManager:managePages'],
            ],
            [
                'key' => 'settings',
                'label' => Craft::t('docs-manager', 'Settings'),
                'url' => 'docs-manager/settings',
                'permissionsAll' => ['docsManager:manageSettings'],
            ],
        ];
    }

    // =========================================================================
    // CP URL RULES
    // =========================================================================

    private function getCpUrlRules(): array
    {
        return [
            // Sources (main page)
            'docs-manager' => 'docs-manager/sources/index',
            'docs-manager/sources' => 'docs-manager/sources/index',
            'docs-manager/sources/new' => 'docs-manager/sources/edit',
            'docs-manager/sources/<sourceId:\d+>' => 'docs-manager/sources/edit',

            // Custom pages
            'docs-manager/pages' => 'docs-manager/pages/index',
            'docs-manager/pages/new/<sourceId:\d+>' => 'docs-manager/pages/edit',
            'docs-manager/pages/<pageId:\d+>' => 'docs-manager/pages/edit',

            // Settings routes
            'docs-manager/settings' => 'docs-manager/settings/index',
            'docs-manager/settings/field-layout' => 'docs-manager/settings/field-layout',
            'docs-manager/settings/<tab:\w+>' => 'docs-manager/settings/<tab>',
        ];
    }

    // =========================================================================
    // PERMISSIONS
    // =========================================================================

    private function getPluginPermissions(): array
    {
        return [
            'docsManager:manageSources' => [
                'label' => Craft::t('docs-manager', 'Manage sources'),
                'nested' => [
                    'docsManager:createSources' => [
                        'label' => Craft::t('docs-manager', 'Create sources'),
                    ],
                    'docsManager:editSources' => [
                        'label' => Craft::t('docs-manager', 'Edit sources'),
                    ],
                    'docsManager:deleteSources' => [
                        'label' => Craft::t('docs-manager', 'Delete sources'),
                    ],
                ],
            ],

            'docsManager:managePages' => [
                'label' => Craft::t('docs-manager', 'Manage custom pages'),
                'nested' => [
                    'docsManager:createPages' => [
                        'label' => Craft::t('docs-manager', 'Create pages'),
                    ],
                    'docsManager:editPages' => [
                        'label' => Craft::t('docs-manager', 'Edit pages'),
                    ],
                    'docsManager:deletePages' => [
                        'label' => Craft::t('docs-manager', 'Delete pages'),
                    ],
                ],
            ],

            'docsManager:viewLogs' => [
                'label' => Craft::t('docs-manager', 'View logs'),
                'nested' => [
                    'docsManager:viewSystemLogs' => [
                        'label' => Craft::t('docs-manager', 'View system logs'),
                        'nested' => [
                            'docsManager:downloadSystemLogs' => [
                                'label' => Craft::t('docs-manager', 'Download system logs'),
                            ],
                        ],
                    ],
                ],
            ],

            'docsManager:manageSettings' => [
                'label' => Craft::t('docs-manager', 'Manage settings'),
            ],
        ];
    }

    // =========================================================================
    // BACKGROUND JOBS
    // =========================================================================

    private function scheduleSyncJob(?Settings $settings = null): void
    {
        $settings ??= $this->getSettings();

        if (!$settings->autoSync) {
            return;
        }

        $existingJob = $this->hasPendingSyncJob();

        if (!$existingJob) {
            $initialDelay = 5 * 60;
            $initialRun = (clone DateFormatHelper::now())->modify("+{$initialDelay} seconds");
            $job = new \lindemannrock\docsmanager\jobs\SyncAllPluginsJob([
                'reschedule' => true,
                'nextRunTime' => DateFormatHelper::formatCompactDatetimeFromSettings(
                    $initialRun,
                    $settings,
                    false,
                    false,
                ),
            ]);

            Craft::$app->getQueue()->delay($initialDelay)->push($job);

            $this->logInfo('Scheduled initial sync job', [
                'delay' => '5 minutes',
                'schedule' => $settings->syncSchedule,
            ]);
        }
    }

    /**
     * Handle automatic sync schedule changes when settings are saved.
     *
     * @since 5.1.0
     */
    public function handleSyncScheduleChange(Settings $newSettings, bool $oldAutoSync, string $oldSyncSchedule): void
    {
        if ($oldAutoSync === $newSettings->autoSync && $oldSyncSchedule === $newSettings->syncSchedule) {
            return;
        }

        $this->cancelScheduledSyncJobs();

        if (!$newSettings->autoSync) {
            $this->logInfo('Automatic docs sync disabled');
            return;
        }

        $this->scheduleSyncJob($newSettings);

        $this->logInfo('Automatic docs sync schedule updated', [
            'schedule' => $newSettings->syncSchedule,
        ]);
    }

    /**
     * Cancel pending automatic sync jobs.
     */
    private function cancelScheduledSyncJobs(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', [
                'and',
                ['like', 'job', 'docsmanager'],
                ['like', 'job', 'SyncAllPluginsJob'],
            ])
            ->execute();
    }

    /**
     * Check whether a pending automatic sync job is already queued.
     */
    private function hasPendingSyncJob(): bool
    {
        return (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'docsmanager'])
            ->andWhere(['like', 'job', 'SyncAllPluginsJob'])
            ->andWhere(['fail' => false])
            ->andWhere(['timeUpdated' => null])
            ->exists();
    }
}
