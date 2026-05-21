<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\PluginPage;
use lindemannrock\docsmanager\records\SourceRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Pages Controller
 *
 * Handles CRUD for custom pages (PluginPage elements).
 *
 * @since 5.0.0
 */
class PagesController extends Controller
{
    /**
     * List all custom pages with optional source filter
     */
    public function actionIndex(?int $sourceId = null): Response
    {
        $this->requirePermission('docsManager:managePages');
        // Resolve current site from request
        $siteHandle = $this->request->getParam('site');
        $currentSite = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)
            : Craft::$app->getSites()->getCurrentSite();

        // Validate site is enabled
        $enabledSites = DocsManager::getInstance()->getEnabledSites();
        $enabledSiteIds = array_map(fn($s) => $s->id, $enabledSites);

        if (!in_array($currentSite->id, $enabledSiteIds)) {
            $firstSite = reset($enabledSites);
            if ($firstSite) {
                return $this->redirect('docs-manager/pages?site=' . $firstSite->handle);
            }
        }

        // Enforce site edit permission (multi-site only)
        if (Craft::$app->getIsMultiSite()) {
            $this->requirePermission('editSite:' . $currentSite->uid);
        }

        $sourceRecords = SourceRecord::find()
            ->where(['enabled' => true])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $query = PluginPage::find()->status(null)->siteId($currentSite->id);
        if ($sourceId) {
            $query->sourceId($sourceId);
        }
        $pages = $query->orderBy(['docsmanager_custom_pages.order' => SORT_ASC])->all();

        return $this->renderTemplate('docs-manager/pages/index', [
            'pages' => $pages,
            'sourceRecords' => $sourceRecords,
            'selectedSourceId' => $sourceId,
        ]);
    }

    /**
     * Edit or create a custom page
     */
    public function actionEdit(?int $pageId = null, ?int $sourceId = null): Response
    {
        // Resolve current site from request
        $siteHandle = $this->request->getParam('site');
        $currentSite = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)
            : Craft::$app->getSites()->getCurrentSite();

        // Enforce site edit permission (multi-site only)
        if (Craft::$app->getIsMultiSite()) {
            $this->requirePermission('editSite:' . $currentSite->uid);
        }

        if ($pageId) {
            $this->requirePermission('docsManager:editPages');
            $page = PluginPage::find()->id($pageId)->siteId($currentSite->id)->status(null)->one();
            if (!$page) {
                throw new NotFoundHttpException('Page not found');
            }
            $sourceId = $page->sourceId;
        } else {
            $this->requirePermission('docsManager:createPages');
            if (!$sourceId) {
                throw new NotFoundHttpException('Source ID is required for new pages');
            }

            $page = new PluginPage();
            $page->sourceId = $sourceId;
            $page->siteId = $currentSite->id;
        }

        // Get the parent source record
        $sourceRecord = SourceRecord::findOne($sourceId);
        if (!$sourceRecord) {
            throw new NotFoundHttpException('Source not found');
        }

        // Get existing page types for this source (to disable in select)
        $existingTypes = PluginPage::find()
            ->sourceId($sourceId)
            ->siteId($currentSite->id)
            ->status(null)
            ->select(['docsmanager_custom_pages.pageType'])
            ->column();

        return $this->renderTemplate('docs-manager/pages/edit', [
            'page' => $page,
            'sourceRecord' => $sourceRecord,
            'isNew' => !$pageId,
            'existingPageTypes' => $existingTypes,
        ]);
    }

    /**
     * Save a custom page
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $pageId = $request->getBodyParam('pageId');

        if ($pageId) {
            $this->requirePermission('docsManager:editPages');
            $page = PluginPage::find()->id($pageId)->status(null)->one();
            if (!$page) {
                throw new NotFoundHttpException('Page not found');
            }
        } else {
            $this->requirePermission('docsManager:createPages');
            $page = new PluginPage();
        }

        $page->title = $request->getBodyParam('title');
        $page->sourceId = (int) $request->getBodyParam('sourceId');
        $page->pageType = $request->getBodyParam('pageType');
        $page->slug = $request->getBodyParam('slug');
        $page->order = (int) $request->getBodyParam('order', 0);
        $page->enabled = (bool) $request->getBodyParam('enabled', true);

        // Set field layout values from the POST data
        $page->setFieldValuesFromRequest('fields');

        if (!Craft::$app->elements->saveElement($page)) {
            Craft::$app->getSession()->setError(Craft::t('docs-manager', 'Couldn\'t save page.'));

            $sourceRecord = SourceRecord::findOne($page->sourceId);

            return $this->renderTemplate('docs-manager/pages/edit', [
                'page' => $page,
                'sourceRecord' => $sourceRecord,
                'isNew' => !$pageId,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('docs-manager', 'Page saved.'));

        return $this->redirectToPostedUrl($page);
    }

    /**
     * Delete a custom page
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:deletePages');

        $pageId = Craft::$app->getRequest()->getBodyParam('pageId');
        $page = PluginPage::find()->id($pageId)->status(null)->one();

        if (!$page) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('docs-manager', 'Page not found'),
            ]);
        }

        if (Craft::$app->elements->deleteElement($page)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('docs-manager', 'Couldn\'t delete page.'),
        ]);
    }

    /**
     * Bulk delete custom pages
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:deletePages');

        $pageIds = Craft::$app->getRequest()->getBodyParam('pageIds', []);
        $count = 0;
        $errors = [];

        foreach ($pageIds as $pageId) {
            $page = PluginPage::find()->id($pageId)->status(null)->one();
            if ($page) {
                if (Craft::$app->elements->deleteElement($page)) {
                    $count++;
                } else {
                    $errors[] = Craft::t('docs-manager', 'Could not delete page "{title}".', ['title' => $page->title]);
                }
            }
        }

        return $this->asJson([
            'success' => $count > 0,
            'count' => $count,
            'errors' => $errors,
        ]);
    }

    /**
     * Bulk enable custom pages
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:editPages');

        $pageIds = Craft::$app->getRequest()->getBodyParam('pageIds', []);
        $count = 0;

        foreach ($pageIds as $pageId) {
            $page = PluginPage::find()->id($pageId)->status(null)->one();
            if ($page && !$page->enabled) {
                $page->enabled = true;
                if (Craft::$app->elements->saveElement($page)) {
                    $count++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Bulk disable custom pages
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:editPages');

        $pageIds = Craft::$app->getRequest()->getBodyParam('pageIds', []);
        $count = 0;

        foreach ($pageIds as $pageId) {
            $page = PluginPage::find()->id($pageId)->status(null)->one();
            if ($page && $page->enabled) {
                $page->enabled = false;
                if (Craft::$app->elements->saveElement($page)) {
                    $count++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }
}
