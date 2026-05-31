<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\controllers;

use Craft;
use craft\models\FieldLayout;
use craft\web\Controller;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\models\Settings;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Settings Controller
 *
 * @since 5.0.0
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('docsManager:manageSettings');

        return true;
    }

    // =========================================================================
    // VIEW ACTIONS
    // =========================================================================

    public function actionIndex(): Response
    {
        return $this->redirect('docs-manager/settings/general');
    }

    public function actionGeneral(): Response
    {
        $settings = DocsManager::getInstance()->getSettings();

        return $this->renderTemplate('docs-manager/settings/general', [
            'settings' => $settings,
            'plugin' => DocsManager::getInstance(),
        ]);
    }

    public function actionInterface(): Response
    {
        $settings = DocsManager::getInstance()->getSettings();

        return $this->renderTemplate('docs-manager/settings/interface', [
            'settings' => $settings,
            'plugin' => DocsManager::getInstance(),
        ]);
    }

    public function actionFrontend(): Response
    {
        $settings = DocsManager::getInstance()->getSettings();

        return $this->renderTemplate('docs-manager/settings/frontend', [
            'settings' => $settings,
            'plugin' => DocsManager::getInstance(),
        ]);
    }

    public function actionAdvanced(): Response
    {
        $settings = DocsManager::getInstance()->getSettings();

        return $this->renderTemplate('docs-manager/settings/advanced', [
            'settings' => $settings,
            'plugin' => DocsManager::getInstance(),
        ]);
    }

    // =========================================================================
    // FIELD LAYOUT
    // =========================================================================

    /**
     * Page field layout settings
     */
    public function actionFieldLayout(): Response
    {
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        $fieldLayouts = Craft::$app->getProjectConfig()->get('docs-manager.pluginPageFieldLayouts') ?? [];

        $fieldLayout = null;

        if (!empty($fieldLayouts)) {
            $fieldLayoutUid = array_key_first($fieldLayouts);
            $fieldLayout = Craft::$app->getFields()->getLayoutByUid($fieldLayoutUid);
        }

        if (!$fieldLayout) {
            $fieldLayout = Craft::$app->getFields()->getLayoutByType(PluginPage::class);
        }

        if (!$fieldLayout) {
            $fieldLayout = new FieldLayout([
                'type' => PluginPage::class,
            ]);

            Craft::$app->getFields()->saveLayout($fieldLayout);

            if (!$readOnly) {
                $fieldLayoutConfig = $fieldLayout->getConfig();
                if ($fieldLayoutConfig) {
                    Craft::$app->getProjectConfig()->set(
                        "docs-manager.pluginPageFieldLayouts.{$fieldLayout->uid}",
                        $fieldLayoutConfig,
                        'Create Custom Page field layout'
                    );
                }
            }
        }

        return $this->renderTemplate('docs-manager/settings/field-layout', [
            'fieldLayout' => $fieldLayout,
            'readOnly' => $readOnly,
        ]);
    }

    /**
     * Save page field layout
     *
     * @throws ForbiddenHttpException
     */
    public function actionSaveFieldLayout(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('docs-manager', 'Administrative changes are disallowed in this environment.'));
        }

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = PluginPage::class;

        if (!Craft::$app->getFields()->saveLayout($fieldLayout)) {
            Craft::$app->getSession()->setError(Craft::t('docs-manager', 'Couldn\'t save field layout.'));
            return null;
        }

        $fieldLayoutConfig = $fieldLayout->getConfig();
        if ($fieldLayoutConfig) {
            Craft::$app->getProjectConfig()->set(
                "docs-manager.pluginPageFieldLayouts.{$fieldLayout->uid}",
                $fieldLayoutConfig,
                'Save Custom Page field layout'
            );
        }

        Craft::$app->getSession()->setNotice(Craft::t('docs-manager', 'Field layout saved.'));

        return $this->redirectToPostedUrl();
    }

    // =========================================================================
    // SAVE ACTION
    // =========================================================================

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = DocsManager::getInstance();
        $section = $this->_validSettingsSection(
            $this->request->getBodyParam('section', 'general'),
        );
        $settings = Settings::loadFromDatabase();
        $oldAutoSync = $settings->autoSync;
        $oldSyncSchedule = $settings->syncSchedule;
        $settingsData = Craft::$app->getRequest()->getBodyParam('settings', []);

        // Handle enabledSites checkbox group
        if (isset($settingsData['enabledSites'])) {
            if (is_array($settingsData['enabledSites'])) {
                $settingsData['enabledSites'] = array_map('intval', array_filter($settingsData['enabledSites']));
            } else {
                $settingsData['enabledSites'] = [];
            }
        } else {
            $settingsData['enabledSites'] = [];
        }

        foreach ($settingsData as $key => $value) {
            if (!$settings->isOverriddenByConfig($key) && property_exists($settings, $key)) {
                // Multi-state selects (e.g. "Use global default" = '') need '' → null
                // so nullable properties hold null, not a coerced false / 0.
                if ($value === '') {
                    $type = (new \ReflectionProperty($settings, $key))->getType();
                    if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                        $value = null;
                    }
                }

                $setterMethod = 'set' . ucfirst($key);
                if (method_exists($settings, $setterMethod)) {
                    $settings->$setterMethod($value);
                } else {
                    $settings->$key = $value;
                }
            }
        }

        $attributesToValidate = $this->_validationAttributesForSection($section);
        $attributesToValidate = array_values(array_filter(
            $attributesToValidate,
            fn(string $attribute): bool => !$settings->isOverriddenByConfig($attribute),
        ));

        if (!$settings->validate($attributesToValidate)) {
            Craft::$app->getSession()->setError(Craft::t('docs-manager', 'Could not save settings.'));

            $template = "docs-manager/settings/{$section}";

            return $this->renderTemplate($template, [
                'settings' => $settings,
                'plugin' => $plugin,
            ]);
        }

        if ($settings->saveToDatabase($attributesToValidate)) {
            if (in_array('autoSync', $attributesToValidate, true) || in_array('syncSchedule', $attributesToValidate, true)) {
                DocsManager::$plugin->handleSyncScheduleChange($settings, $oldAutoSync, $oldSyncSchedule);
            }

            Craft::$app->getSession()->setNotice(Craft::t('docs-manager', 'Settings saved.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('docs-manager', 'Could not save settings.'));
            return null;
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Validate and sanitize the settings section parameter
     *
     * @param string $section The section from POST data
     * @return string A validated section name
     */
    private function _validSettingsSection(string $section): string
    {
        $allowed = ['general', 'interface', 'frontend', 'advanced'];

        return in_array($section, $allowed, true) ? $section : 'general';
    }

    /**
     * Get settings attributes that belong to a section
     *
     * @param string $section
     * @return array<int, string>
     */
    private function _validationAttributesForSection(string $section): array
    {
        return match ($section) {
            'general' => [
                'pluginName',
                'enabledSites',
                'defaultSourceType',
                'localPluginBasePath',
                'githubToken',
                'logLevel',
            ],
            'interface' => [
                'itemsPerPage',
                'timeFormat',
                'monthFormat',
                'dateOrder',
                'dateSeparator',
                'showSeconds',
            ],
            'frontend' => [
                'enableSyntaxHighlighting',
                'codeTheme',
                'codeFontSize',
                'codeFontFamily',
                'codeShowLineNumbers',
                'codeEnableCopyButton',
                'enableAnchorGeneration',
            ],
            'advanced' => ['autoSync', 'syncSchedule'],
            default => [],
        };
    }
}
