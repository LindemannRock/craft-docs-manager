<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\records\SourceRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Sources Controller
 *
 * Manages source records (add, edit, delete, scan)
 *
 * @since 5.0.0
 */
class SourcesController extends Controller
{
    /**
     * List all sources
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('docsManager:manageSources');

        $request = Craft::$app->getRequest();
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
                return $this->redirect('docs-manager/sources?site=' . $firstSite->handle);
            }
        }

        // Enforce site edit permission (multi-site only)
        if (Craft::$app->getIsMultiSite()) {
            $this->requirePermission('editSite:' . $currentSite->uid);
        }

        // Get query parameters
        $search = $request->getQueryParam('search', '');
        $statusFilter = $request->getQueryParam('status', 'all');
        $kindFilter = $request->getQueryParam('kind', 'all');
        $sort = $request->getQueryParam('sort', 'name');
        $dir = $request->getQueryParam('dir', 'asc');
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = $settings->itemsPerPage ?? 50;

        // Get all sources
        $query = SourceRecord::find();

        // Apply status filter
        if ($statusFilter === 'enabled') {
            $query->where(['enabled' => true]);
        } elseif ($statusFilter === 'disabled') {
            $query->where(['enabled' => false]);
        }

        // Apply kind filter
        if ($kindFilter !== 'all') {
            $query->andWhere(['kind' => $kindFilter]);
        }

        // Apply search filter
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'name', $search],
                ['like', 'handle', $search],
            ]);
        }

        // Get total count before pagination
        $totalCount = $query->count();
        $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;

        // Ensure page is within bounds
        $page = min($page, $totalPages);

        // Apply sorting
        $allowedSortFields = ['name', 'handle', 'kind', 'lastSyncedAt', 'enabled'];
        $sortField = in_array($sort, $allowedSortFields, true) ? $sort : 'name';
        $sortDirection = $dir === 'desc' ? SORT_DESC : SORT_ASC;

        // Apply pagination
        $offset = ($page - 1) * $limit;
        $sources = $query
            ->orderBy([$sortField => $sortDirection])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return $this->renderTemplate('docs-manager/sources/index', [
            'sources' => $sources,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'kindFilter' => $kindFilter,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Edit/create source
     */
    public function actionEdit(?int $sourceId = null): Response
    {
        if ($sourceId) {
            $this->requirePermission('docsManager:editSources');
            $source = SourceRecord::findOne($sourceId);
            if (!$source) {
                throw new NotFoundHttpException('Source not found');
            }
        } else {
            $this->requirePermission('docsManager:createSources');
            $source = new SourceRecord();
            $source->enabled = true;
            $source->kind = 'plugin';
        }

        return $this->renderTemplate('docs-manager/sources/edit', [
            'source' => $source,
            'isNew' => !$sourceId,
        ]);
    }

    /**
     * Save source
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $sourceId = Craft::$app->getRequest()->getBodyParam('sourceId');

        if ($sourceId) {
            $this->requirePermission('docsManager:editSources');
            $source = SourceRecord::findOne($sourceId);
            if (!$source) {
                throw new NotFoundHttpException('Source not found');
            }
        } else {
            $this->requirePermission('docsManager:createSources');
            $source = new SourceRecord();
        }

        // Get form data
        $sourceData = Craft::$app->getRequest()->getBodyParam('source', []);
        $isNewSource = !$sourceId;

        $source->name = $sourceData['name'] ?? null;
        $source->handle = $sourceData['handle'] ?? null;
        $source->kind = $sourceData['kind'] ?? 'plugin';
        $source->description = $sourceData['description'] ?? null;
        $source->sourceType = $sourceData['sourceType'] ?? 'local';
        $source->repositoryUrl = $sourceData['repositoryUrl'] ?? null;
        $source->localPath = $sourceData['localPath'] ?? null;
        $source->enabled = (bool) ($sourceData['enabled'] ?? true);

        if (!$source->validate() || !$source->save()) {
            $this->setFailFlash(Craft::t('docs-manager', 'Could not save source.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'source' => $source,
            ]);

            return null;
        }

        // Queue initial sync for new sources
        if ($isNewSource) {
            $job = new \lindemannrock\docsmanager\jobs\SyncSinglePluginJob([
                'sourceHandle' => $source->handle,
            ]);
            Craft::$app->getQueue()->push($job);
        }

        Craft::$app->getSession()->setNotice(Craft::t('docs-manager', 'Source saved.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Delete source
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:deleteSources');

        $sourceId = Craft::$app->getRequest()->getBodyParam('sourceId');
        $source = SourceRecord::findOne($sourceId);

        if (!$source) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('docs-manager', 'Source not found'),
            ]);
        }

        if ($source->delete()) {
            return $this->asJson([
                'success' => true,
            ]);
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('docs-manager', 'Failed to delete source'),
        ]);
    }

    /**
     * Sync a single source
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:editSources');

        $sourceId = Craft::$app->getRequest()->getBodyParam('sourceId');
        $sourceRecord = SourceRecord::findOne($sourceId);

        if (!$sourceRecord) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('docs-manager', 'Source not found'),
            ]);
        }

        try {
            $result = DocsManager::getInstance()->sync->syncPlugin($sourceRecord->handle);

            if ($result['success']) {
                return $this->asJson([
                    'success' => true,
                    'message' => Craft::t('docs-manager', 'Source synced successfully'),
                ]);
            } else {
                return $this->asJson([
                    'success' => false,
                    'error' => $result['errors'][0] ?? Craft::t('docs-manager', 'Sync failed'),
                ]);
            }
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync all enabled sources
     */
    public function actionSyncAll(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:editSources');

        try {
            $results = DocsManager::getInstance()->sync->syncAllPlugins();

            return $this->asJson([
                'success' => true,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Scan local sources folder and show available sources
     */
    public function actionScanLocal(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('docsManager:editSources');

        $settings = DocsManager::getInstance()->getSettings();
        $basePath = Craft::getAlias($settings->localPluginBasePath);

        if (!is_dir($basePath)) {
            Craft::$app->getSession()->setError(Craft::t('docs-manager', 'Base path not found: {path}', ['path' => $basePath]));
            return $this->asJson(['success' => false]);
        }

        // Scan for sources (folders with docs/index.json)
        $availableSources = [];
        $dirs = glob($basePath . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $handle = basename($dir);

            // Skip if starts with _ or .
            if (str_starts_with($handle, '_') || str_starts_with($handle, '.')) {
                continue;
            }

            $composerFile = $dir . '/composer.json';
            $indexFile = $dir . '/docs/index.json';
            $pluginJsonFile = $dir . '/docs/plugin.json';
            $themeJsonFile = $dir . '/docs/theme.json';

            // REQUIRE docs/index.json - skip sources without it
            if (!file_exists($indexFile)) {
                continue;
            }

            // Determine kind: docs/plugin.json → plugin, docs/theme.json → theme
            // Must have one or the other
            if (file_exists($pluginJsonFile)) {
                $kind = 'plugin';
            } elseif (file_exists($themeJsonFile)) {
                $kind = 'theme';
            } else {
                continue; // Skip sources without plugin.json or theme.json
            }

            // Check if already added
            $sourceHandle = $handle;
            if (file_exists($composerFile)) {
                $composerData = json_decode(file_get_contents($composerFile), true);
                $sourceHandle = $composerData['extra']['handle'] ?? $handle;
            }

            $exists = SourceRecord::findOne(['handle' => $sourceHandle]);
            if ($exists) {
                continue;
            }

            // Build name/description from docs/index.json, fallback to composer.json
            $name = ucwords(str_replace('-', ' ', $sourceHandle));
            $description = '';

            if (file_exists($composerFile)) {
                $composerData ??= json_decode(file_get_contents($composerFile), true);
                $name = $composerData['extra']['name'] ?? $name;
                $description = $composerData['description'] ?? '';
            }

            // docs/index.json overrides
            $indexData = json_decode(file_get_contents($indexFile), true);
            $name = $indexData['plugin']['name'] ?? $name;
            $description = $indexData['plugin']['description'] ?? $description;

            $availableSources[] = [
                'handle' => $sourceHandle,
                'name' => $name,
                'description' => $description,
                'localPath' => '@root/plugins/' . $handle,
                'kind' => $kind,
            ];
        }

        return $this->asJson([
            'success' => true,
            'sources' => $availableSources,
        ]);
    }

    /**
     * Scan GitHub organization for sources
     */
    public function actionScanGithub(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('docsManager:editSources');

        $settings = DocsManager::getInstance()->getSettings();
        $token = $settings->githubToken;

        if (!$token) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('docs-manager', 'GitHub token is required. Please configure it in settings.'),
            ]);
        }

        $token = App::env($token);

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.github.com/orgs/LindemannRock/repos?per_page=100&type=all',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'User-Agent: CraftCMS-Docs-Manager',
                    'Accept: application/vnd.github.v3+json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('docs-manager', 'GitHub API error: {code}', ['code' => $httpCode]),
                ]);
            }

            $repos = json_decode($response, true);
            $availableSources = [];
            $missingIndexJson = [];

            foreach ($repos as $repo) {
                if (!str_starts_with($repo['name'], 'craft-')) {
                    continue;
                }

                $handle = str_replace('craft-', '', $repo['name']);

                $exists = SourceRecord::findOne(['handle' => $handle]);
                if ($exists) {
                    continue;
                }

                // Check if repo has docs/index.json (REQUIRED)
                $defaultBranch = $repo['default_branch'] ?? 'main';
                $indexCheckUrl = "https://api.github.com/repos/LindemannRock/{$repo['name']}/contents/docs/index.json?ref={$defaultBranch}";

                $ch2 = curl_init();
                curl_setopt_array($ch2, [
                    CURLOPT_URL => $indexCheckUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $token,
                        'User-Agent: CraftCMS-Docs-Manager',
                        'Accept: application/vnd.github.v3+json',
                    ],
                ]);

                $indexResponse = curl_exec($ch2);
                $indexHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);

                if ($indexHttpCode !== 200) {
                    $missingIndexJson[] = [
                        'name' => $repo['name'],
                        'handle' => $handle,
                        'url' => $repo['html_url'],
                    ];
                    continue;
                }

                $availableSources[] = [
                    'name' => ucwords(str_replace('-', ' ', $handle)),
                    'handle' => $handle,
                    'description' => $repo['description'] ?? '',
                    'repositoryUrl' => $repo['html_url'],
                    'kind' => 'plugin',
                ];
            }

            return $this->asJson([
                'success' => true,
                'sources' => $availableSources,
                'missingIndexJson' => $missingIndexJson,
            ]);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add source from scan results
     */
    public function actionAddFromScan(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('docsManager:createSources');

        $sourceData = Craft::$app->getRequest()->getBodyParam('source');

        $source = new SourceRecord();
        $source->name = $sourceData['name'];
        $source->handle = $sourceData['handle'];
        $source->kind = $sourceData['kind'] ?? 'plugin';
        $source->description = $sourceData['description'] ?? null;
        $source->localPath = $sourceData['localPath'];
        $source->enabled = true;

        if ($source->save()) {
            Craft::$app->getSession()->setNotice(Craft::t('docs-manager', 'Source added: {name}', ['name' => $source->name]));
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not save source']);
    }

    /**
     * Bulk enable sources
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('docsManager:editSources');
        $sourceIds = Craft::$app->getRequest()->getBodyParam('sourceIds', []);

        $updated = SourceRecord::updateAll(['enabled' => true], ['id' => $sourceIds]);

        return $this->asJson(['success' => true, 'updated' => $updated]);
    }

    /**
     * Bulk disable sources
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('docsManager:editSources');
        $sourceIds = Craft::$app->getRequest()->getBodyParam('sourceIds', []);

        $updated = SourceRecord::updateAll(['enabled' => false], ['id' => $sourceIds]);

        return $this->asJson(['success' => true, 'updated' => $updated]);
    }

    /**
     * Bulk delete sources
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('docsManager:deleteSources');
        $sourceIds = Craft::$app->getRequest()->getBodyParam('sourceIds', []);

        $deleted = SourceRecord::deleteAll(['id' => $sourceIds]);

        return $this->asJson(['success' => true, 'deleted' => $deleted]);
    }
}
