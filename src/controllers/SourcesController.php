<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\web\Controller;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\helpers\LocalSourcePathHelper;
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
        $user = Craft::$app->getUser();

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

        // ---- Param parsing + allowlist validation -------------------------
        // Every parameter that controls filtering or sorting goes through an
        // explicit allowlist. Off-list values snap back to the default.

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $kindFilter = (string) $request->getQueryParam('kind', 'all');
        $validKinds = ['all', 'plugin', 'theme'];
        if (!in_array($kindFilter, $validKinds, true)) {
            $kindFilter = 'all';
        }

        // 64-char defensive clamp on free-text search. Keeps a runaway payload
        // (URL of any length) from reaching the LIKE comparison.
        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'kind', 'currentVersion', 'pages', 'lastSyncedAt', 'enabled'];
        $sort = (string) $request->getQueryParam('sort', 'name');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string) $request->getQueryParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $page = max(1, (int) $request->getQueryParam('page', 1));
        $limit = max(1, (int) ($settings->itemsPerPage ?? 50));

        // ---- Load + filter ------------------------------------------------
        // In-memory variant: source counts are bounded (a workspace's plugin/
        // theme catalog rarely exceeds a few dozen). Loading all matching
        // rows lets us sort by the computed `pages` count without an N+1.
        $query = SourceRecord::find();

        if ($statusFilter === 'enabled') {
            $query->where(['enabled' => true]);
        } elseif ($statusFilter === 'disabled') {
            $query->where(['enabled' => false]);
        }

        if ($kindFilter !== 'all') {
            $query->andWhere(['kind' => $kindFilter]);
        }

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'name', $search],
                ['like', 'handle', $search],
            ]);
        }

        /** @var SourceRecord[] $sources */
        $sources = $query->all();

        // Pre-compute synced-page counts per source via a single GROUP BY
        // query. The template's `craft.docsManager.getPages(handle)|length`
        // call hits this same data; eagerly resolving here keeps the row
        // render N=1 (was N+1: one query per visible row).
        $pageCounts = $this->_loadPageCounts();

        // ---- Sort + paginate ----------------------------------------------
        $sources = $this->_sortSources($sources, $sort, $dir, $pageCounts);

        // totalCount is computed *after* filtering so the pager reflects what
        // the user can actually see, not the underlying table size.
        $totalCount = count($sources);
        $offset = ($page - 1) * $limit;
        $sources = array_slice($sources, $offset, $limit);

        return $this->renderTemplate('docs-manager/sources/index', [
            'sources' => $sources,
            'pageCounts' => $pageCounts,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'kindFilter' => $kindFilter,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'canCreate' => $user->checkPermission('docsManager:createSources'),
            'canEdit' => $user->checkPermission('docsManager:editSources'),
            'canDelete' => $user->checkPermission('docsManager:deleteSources'),
            'canManage' => $user->checkPermission('docsManager:manageSources'),
        ]);
    }

    /**
     * Single GROUP BY query that maps sourceId → synced-page count for every
     * source in `{{%docsmanager_pages}}`. Used to drive the `pages` column's
     * sort and to render the count without an N+1 lookup per row.
     *
     * @return array<int, int> sourceId → count
     */
    private function _loadPageCounts(): array
    {
        $rows = (new Query())
            ->select(['sourceId', 'pageCount' => 'COUNT(*)'])
            ->from('{{%docsmanager_pages}}')
            ->groupBy(['sourceId'])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['sourceId']] = (int) $row['pageCount'];
        }

        return $map;
    }

    /**
     * @param SourceRecord[] $sources
     * @param array<int, int> $pageCounts sourceId → count
     * @return SourceRecord[]
     */
    private function _sortSources(array $sources, string $sort, string $dir, array $pageCounts): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($sources, function(SourceRecord $a, SourceRecord $b) use ($sort, $multiplier, $pageCounts): int {
            $cmp = match ($sort) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'kind' => strcasecmp((string) $a->kind, (string) $b->kind),
                'currentVersion' => strnatcasecmp((string) $a->currentVersion, (string) $b->currentVersion),
                'pages' => ($pageCounts[$a->id] ?? 0) <=> ($pageCounts[$b->id] ?? 0),
                'lastSyncedAt' => strcmp((string) $a->lastSyncedAt, (string) $b->lastSyncedAt),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            // Stable tie-break by name so equal primary keys don't shuffle
            // between requests — keeps pagination predictable.
            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $sources;
    }

    /**
     * Edit/create source
     */
    public function actionEdit(?int $sourceId = null, ?SourceRecord $source = null, ?bool $isNew = null): Response
    {
        if ($sourceId) {
            $this->requirePermission('docsManager:editSources');
            $source ??= SourceRecord::findOne($sourceId);
            if (!$source) {
                throw new NotFoundHttpException(Craft::t('docs-manager', 'Source not found'));
            }
        } else {
            $this->requirePermission('docsManager:createSources');
            if (!$source) {
                $source = new SourceRecord();
                $source->enabled = true;
                $source->kind = 'plugin';
            }
        }

        return $this->renderTemplate('docs-manager/sources/edit', [
            'source' => $source,
            'isNew' => $isNew ?? !$sourceId,
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
                throw new NotFoundHttpException(Craft::t('docs-manager', 'Source not found'));
            }
        } else {
            $this->requirePermission('docsManager:createSources');
            $source = new SourceRecord();
        }

        // Get form data
        $sourceData = Craft::$app->getRequest()->getBodyParam('source', []);
        $isNewSource = !$sourceId;

        $source->name = $sourceData['name'] ?? null;
        $source->handle = SlugHandleHelper::normalizeSlug($sourceData['handle'] ?? null, '');
        $source->kind = $sourceData['kind'] ?? 'plugin';
        $source->description = $sourceData['description'] ?? null;
        $source->sourceType = $sourceData['sourceType'] ?? 'local';
        $source->repositoryUrl = $sourceData['repositoryUrl'] ?? null;
        $source->localPath = $sourceData['localPath'] ?? null;
        $source->enabled = (bool) ($sourceData['enabled'] ?? true);

        if ($isNewSource && $source->handle !== '') {
            $source->handle = SlugHandleHelper::makeUnique('{{%docsmanager_sources}}', 'handle', $source->handle);
        }

        if (!$source->validate() || !$source->save()) {
            $this->setFailFlash(Craft::t('docs-manager', 'Could not save source.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'source' => $source,
                'isNew' => $isNewSource,
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
        return $this->redirectToPostedUrl($source);
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
        $basePath = LocalSourcePathHelper::resolve((string) $settings->localPluginBasePath);

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
            $sourceHandle = SlugHandleHelper::normalizeSlug($handle, '');
            if (file_exists($composerFile)) {
                $composerData = json_decode(file_get_contents($composerFile), true);
                $sourceHandle = SlugHandleHelper::normalizeSlug($composerData['extra']['handle'] ?? $handle, '');
            }
            if ($sourceHandle === '') {
                continue;
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
                'sourceType' => 'local',
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

                $handle = SlugHandleHelper::normalizeSlug(str_replace('craft-', '', $repo['name']), '');
                if ($handle === '') {
                    continue;
                }

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
                    'sourceType' => 'github-api',
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
        $source->handle = SlugHandleHelper::normalizeSlug($sourceData['handle'] ?? null, '');
        $source->kind = $sourceData['kind'] ?? 'plugin';
        $source->sourceType = $sourceData['sourceType'] ?? (!empty($sourceData['repositoryUrl']) ? 'github-api' : 'local');
        $source->description = $sourceData['description'] ?? null;
        $source->repositoryUrl = $sourceData['repositoryUrl'] ?? null;
        $source->localPath = $sourceData['localPath'] ?? null;
        $source->enabled = true;

        if ($source->handle !== '') {
            $source->handle = SlugHandleHelper::makeUnique('{{%docsmanager_sources}}', 'handle', $source->handle);
        }

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
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:editSources');
        $sourceIds = Craft::$app->getRequest()->getBodyParam('sourceIds', []);

        $count = SourceRecord::updateAll(['enabled' => true], ['id' => $sourceIds]);

        return $this->asJson(['success' => true, 'count' => $count]);
    }

    /**
     * Bulk disable sources
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:editSources');
        $sourceIds = Craft::$app->getRequest()->getBodyParam('sourceIds', []);

        $count = SourceRecord::updateAll(['enabled' => false], ['id' => $sourceIds]);

        return $this->asJson(['success' => true, 'count' => $count]);
    }

    /**
     * Bulk delete sources
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('docsManager:deleteSources');
        $sourceIds = Craft::$app->getRequest()->getBodyParam('sourceIds', []);

        $count = SourceRecord::deleteAll(['id' => $sourceIds]);

        return $this->asJson(['success' => true, 'count' => $count]);
    }
}
