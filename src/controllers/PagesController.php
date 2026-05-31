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

        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser();
        $settings = DocsManager::getInstance()->getSettings();

        // Resolve current site from request
        $siteHandle = $request->getParam('site');
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

        // ---- Param parsing + allowlist validation -------------------------
        // Every parameter that controls filtering or sorting goes through an
        // explicit allowlist. Off-list values snap back to the default.

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $typeFilter = (string) $request->getQueryParam('type', 'all');
        $validTypes = ['all', 'features', 'faq', 'support', 'pricing', 'custom'];
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }

        // 64-char defensive clamp on free-text search.
        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['title', 'source', 'pageType', 'slug', 'enabled'];
        $sort = (string) $request->getQueryParam('sort', 'title');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'title';
        }
        $dir = strtolower((string) $request->getQueryParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $page = max(1, (int) $request->getQueryParam('page', 1));
        $limit = max(1, (int) ($settings->itemsPerPage ?? 50));

        // ---- Load + filter ------------------------------------------------
        /** @var SourceRecord[] $sourceRecords */
        $sourceRecords = SourceRecord::find()
            ->where(['enabled' => true])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        // Build source lookup map for both filter resolution and sort comparison.
        $sourceMap = [];
        foreach ($sourceRecords as $sourceRecord) {
            $sourceMap[$sourceRecord->id] = $sourceRecord;
        }

        $query = PluginPage::find()->status(null)->siteId($currentSite->id);
        if ($sourceId) {
            $query->sourceId($sourceId);
        }

        // Load all pages for this site/source — the existing Twig pattern was
        // in-memory; keeping that shape since custom-pages counts are bounded
        // (handful of `pageType` values per source).
        /** @var PluginPage[] $pages */
        $pages = $query->orderBy(['docsmanager_custom_pages.order' => SORT_ASC])->all();

        if ($statusFilter === 'enabled') {
            $pages = array_values(array_filter($pages, fn(PluginPage $p): bool => (bool) $p->enabled));
        } elseif ($statusFilter === 'disabled') {
            $pages = array_values(array_filter($pages, fn(PluginPage $p): bool => !$p->enabled));
        }

        if ($typeFilter !== 'all') {
            $pages = array_values(array_filter($pages, fn(PluginPage $p): bool => $p->pageType === $typeFilter));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $pages = array_values(array_filter($pages, fn(PluginPage $p): bool =>
                str_contains(mb_strtolower((string) $p->title), $needle) ||
                str_contains(mb_strtolower((string) ($p->slug ?? '')), $needle)
            ));
        }

        // ---- Sort + paginate ----------------------------------------------
        $pages = $this->sortPages($pages, $sort, $dir, $sourceMap);

        // totalCount is computed *after* filtering so the pager reflects what
        // the user can actually see, not the underlying set size.
        $totalCount = count($pages);
        $offset = ($page - 1) * $limit;
        $pages = array_slice($pages, $offset, $limit);

        return $this->renderTemplate('docs-manager/pages/index', [
            'pages' => $pages,
            'sourceRecords' => $sourceRecords,
            'selectedSourceId' => $sourceId,
            'statusFilter' => $statusFilter,
            'typeFilter' => $typeFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => $user->checkPermission('docsManager:createPages'),
            'canEdit' => $user->checkPermission('docsManager:editPages'),
            'canDelete' => $user->checkPermission('docsManager:deletePages'),
            'canManage' => $user->checkPermission('docsManager:managePages'),
        ]);
    }

    /**
     * @param PluginPage[] $pages
     * @param array<int, SourceRecord> $sourceMap
     * @return PluginPage[]
     */
    private function sortPages(array $pages, string $sort, string $dir, array $sourceMap): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($pages, function(PluginPage $a, PluginPage $b) use ($sort, $multiplier, $sourceMap): int {
            $cmp = match ($sort) {
                'source' => strcasecmp(
                    (string) ($sourceMap[$a->sourceId]->name ?? ''),
                    (string) ($sourceMap[$b->sourceId]->name ?? '')
                ),
                'pageType' => strcasecmp((string) $a->pageType, (string) $b->pageType),
                'slug' => strcasecmp((string) ($a->slug ?? ''), (string) ($b->slug ?? '')),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->title, (string) $b->title),
            };

            // Stable tie-break by title so equal primary keys don't shuffle
            // between requests — keeps pagination predictable.
            if ($cmp === 0 && $sort !== 'title') {
                $cmp = strcasecmp((string) $a->title, (string) $b->title);
            }

            return $cmp * $multiplier;
        });

        return $pages;
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
                throw new NotFoundHttpException(Craft::t('docs-manager', 'Page not found'));
            }
            $sourceId = $page->sourceId;
        } else {
            $this->requirePermission('docsManager:createPages');
            if (!$sourceId) {
                throw new NotFoundHttpException(Craft::t('docs-manager', 'Source ID is required for new pages.'));
            }

            $page = new PluginPage();
            $page->sourceId = $sourceId;
            $page->siteId = $currentSite->id;
        }

        // Get the parent source record
        $sourceRecord = SourceRecord::findOne($sourceId);
        if (!$sourceRecord) {
            throw new NotFoundHttpException(Craft::t('docs-manager', 'Source not found'));
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
                throw new NotFoundHttpException(Craft::t('docs-manager', 'Page not found'));
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
