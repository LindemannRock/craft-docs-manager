<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\helpers\LocalSourcePathHelper;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\docsmanager\records\SourceVersionRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Sync Service
 *
 * Orchestrates the documentation sync process:
 * - Reads plugin docs/index.json
 * - Fetches markdown files
 * - Parses content
 * - Creates/updates database records
 *
 * @since 5.0.0
 */
class SyncService extends Component
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('docs-manager');
    }

    /**
     * Sync a single plugin's documentation
     *
     * @param string $handle Plugin handle
     * @return array Results ['success' => bool, 'pages' => int, 'errors' => array]
     */
    public function syncPlugin(string $handle): array
    {
        $results = [
            'success' => false,
            'pages' => 0,
            'errors' => [],
            'version' => null,
            'versions' => [],
        ];

        try {
            // 1. Get or create plugin record
            $plugin = $this->getOrCreatePlugin($handle);
            if (!$plugin) {
                $results['errors'][] = "Plugin record not found: {$handle}";
                return $results;
            }

            // 2. Get plugin path
            $pluginPath = $this->getPluginPath($plugin);
            if (!$pluginPath) {
                $results['errors'][] = "Plugin path not found";
                return $results;
            }

            $versions = $this->getConfiguredVersions($plugin);
            foreach ($versions as $version) {
                $versionResult = $this->syncPluginVersion($plugin, $pluginPath, $version);
                $results['pages'] += $versionResult['pages'];
                $results['errors'] = array_merge($results['errors'], $versionResult['errors']);
                $results['versions'][] = [
                    'label' => $version->label,
                    'slug' => $version->slug,
                    'ref' => $version->ref,
                    'pages' => $versionResult['pages'],
                    'success' => $versionResult['errors'] === [],
                ];

                if ($version->isDefault && $versionResult['version']) {
                    $results['version'] = $versionResult['version'];
                }
            }

            $plugin->lastSyncedAt = gmdate('Y-m-d H:i:s');
            $plugin->save();

            $results['success'] = $results['errors'] === [];
        } catch (\Exception $e) {
            $results['errors'][] = "Sync failed: {$e->getMessage()}";
            $this->logError('Failed to sync plugin', ['handle' => $handle, 'error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * @return SourceVersionRecord[]
     */
    protected function getConfiguredVersions(SourceRecord $plugin): array
    {
        /** @var SourceVersionRecord[] $versions */
        $versions = SourceVersionRecord::find()
            ->where(['sourceId' => $plugin->id])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        if ($versions !== []) {
            return $versions;
        }

        $version = new SourceVersionRecord();
        $version->sourceId = $plugin->id;
        $version->label = $plugin->currentVersion ? preg_replace('/^(\d+).*/', '$1.x', $plugin->currentVersion) : 'Current';
        $version->slug = null;
        $version->ref = 'main';
        $version->status = SourceVersionRecord::STATUS_LATEST;
        $version->isDefault = true;
        $version->sortOrder = 0;
        $version->save(false);

        return [$version];
    }

    /**
     * Sync one configured docs version for a source.
     *
     * @return array{pages: int, errors: array<int, string>, version: string|null}
     */
    protected function syncPluginVersion(SourceRecord $plugin, string $pluginPath, SourceVersionRecord $version): array
    {
        $result = [
            'pages' => 0,
            'errors' => [],
            'version' => null,
        ];
        $versionSlug = $this->pageVersionValue($version);

        try {
            $sidebarData = $this->loadSidebar($plugin, $pluginPath, $version);
            if (!$sidebarData) {
                throw new \Exception("No .sidebar.json found for {$version->label}");
            }

            if ((bool) $version->isDefault) {
                $this->syncDefaultSourceMetadata($plugin, $pluginPath, $version, $result);
            }

            $syncedSlugs = [];
            $globalOrder = 0;
            foreach ($sidebarData as $section) {
                $sectionTitle = $section['title'] ?? 'Unknown';
                $children = $section['children'] ?? [];

                foreach ($children as $childPath) {
                    try {
                        $slug = SlugHandleHelper::normalizePathSlug((string) $childPath, '');
                        if ($slug === '') {
                            $result['errors'][] = "Failed to sync '{$childPath}' ({$version->label}): normalized slug is empty";
                            continue;
                        }
                        if (isset($syncedSlugs[$slug])) {
                            $result['errors'][] = "Failed to sync '{$childPath}' ({$version->label}): normalized slug '{$slug}' is duplicated in the sidebar";
                            continue;
                        }

                        $globalOrder++;
                        $this->syncPageFromFile($plugin, $pluginPath, $version, $sectionTitle, $childPath, $globalOrder);
                        $syncedSlugs[$slug] = true;
                        $result['pages']++;
                    } catch (\Exception $e) {
                        $result['errors'][] = "Failed to sync '{$childPath}' ({$version->label}): {$e->getMessage()}";
                    }
                }
            }

            $this->cleanupOrphanPages($plugin->id, $versionSlug, array_keys($syncedSlugs));
            $version->lastSyncedAt = gmdate('Y-m-d H:i:s');
            $version->lastSyncStatus = $result['errors'] === [] ? 'success' : 'error';
            $version->lastSyncError = $result['errors'] === [] ? null : implode("\n", $result['errors']);
            $version->save(false);
        } catch (\Exception $e) {
            $message = "Failed to sync {$version->label}: {$e->getMessage()}";
            $result['errors'][] = $message;
            $version->lastSyncStatus = 'error';
            $version->lastSyncError = $message;
            $version->save(false);
        }

        return $result;
    }

    /**
     * @param array{pages: int, errors: array<int, string>, version: string|null} $result
     */
    protected function syncDefaultSourceMetadata(SourceRecord $plugin, string $pluginPath, SourceVersionRecord $version, array &$result): void
    {
        if ($plugin->sourceType !== 'github-api') {
            $versionData = DocsManager::getInstance()->versionDetector->getPluginVersion($plugin->handle, $pluginPath);
        } else {
            $versionData = null;
        }

        if ($versionData) {
            $plugin->currentVersion = $versionData['version'];

            if ($versionData['releaseDate']) {
                try {
                    $date = new \DateTime($versionData['releaseDate']);
                    $plugin->releaseDate = $date->format('Y-m-d H:i:s');
                } catch (\Exception) {
                    $plugin->releaseDate = null;
                }
            } else {
                $plugin->releaseDate = null;
            }

            $result['version'] = $versionData['version'];
        }

        try {
            if ($plugin->sourceType === 'github-api') {
                $changelogContent = $this->fetchGithubFile($plugin, 'CHANGELOG.md', $version->ref);
            } else {
                $changelogPath = $pluginPath . '/CHANGELOG.md';
                $changelogContent = file_exists($changelogPath) ? file_get_contents($changelogPath) : null;
            }
            $plugin->changelogContent = $changelogContent ?: null;
        } catch (\Exception $e) {
            $this->logInfo('Changelog not found', ['handle' => $plugin->handle, 'error' => $e->getMessage()]);
        }

        try {
            if ($plugin->sourceType === 'github-api') {
                $iconContent = $this->fetchGithubFile($plugin, 'src/icon.svg', $version->ref);
                $plugin->iconSvg = is_string($iconContent) ? $iconContent : null;
            } else {
                $iconPath = $pluginPath . '/src/icon.svg';
                $plugin->iconSvg = file_exists($iconPath) ? file_get_contents($iconPath) : null;
            }
        } catch (\Exception) {
            $plugin->iconSvg = null;
        }

        try {
            if ($plugin->sourceType === 'github-api') {
                $iconMaskContent = $this->fetchGithubFile($plugin, 'src/icon-mask.svg', $version->ref);
            } else {
                $iconMaskPath = $pluginPath . '/src/icon-mask.svg';
                $iconMaskContent = file_exists($iconMaskPath) ? file_get_contents($iconMaskPath) : null;
            }
            $this->setSourceMetadataValue($plugin, 'iconMaskSvg', is_string($iconMaskContent) ? $iconMaskContent : null);
        } catch (\Exception) {
            $this->setSourceMetadataValue($plugin, 'iconMaskSvg', null);
        }
    }

    /**
     * Load sidebar structure from .sidebar.json
     *
     * @param SourceRecord $plugin Plugin record
     * @param string $pluginPath Plugin path
     * @return array|null Sidebar data
     */
    protected function loadSidebar(SourceRecord $plugin, string $pluginPath, SourceVersionRecord $version): ?array
    {
        if ($plugin->sourceType === 'github-api') {
            return $this->fetchGithubFile($plugin, 'docs/.sidebar.json', $version->ref);
        }

        $content = $this->readLocalFile($pluginPath, 'docs/.sidebar.json', $version);

        return $content === null ? null : json_decode($content, true);
    }

    /**
     * Sync a page from a markdown file using the element API
     *
     * @param SourceRecord $plugin Plugin record
     * @param string $pluginPath Plugin path
     * @param string $category Category/section title
     * @param string $filePath Relative path (e.g., "get-started/requirements")
     * @param int $order Page order
     */
    protected function syncPageFromFile(SourceRecord $plugin, string $pluginPath, SourceVersionRecord $version, string $category, string $filePath, int $order): void
    {
        // Build full path to markdown file
        $fullPath = $pluginPath . '/docs/' . $filePath . '.md';

        if ($plugin->sourceType === 'github-api') {
            $markdown = $this->fetchGithubFile($plugin, 'docs/' . $filePath . '.md', $version->ref);
        } else {
            $markdown = $this->readLocalFile($pluginPath, 'docs/' . $filePath . '.md', $version);
            if ($markdown === null) {
                throw new \Exception("File not found: {$fullPath}");
            }
        }

        // Parse markdown
        $parsed = DocsManager::getInstance()->parser->parseMarkdown($markdown, null, true, $this->buildImageBaseUrl($plugin, $version));

        // Extract title from first H1 heading or use filename
        $title = $this->extractTitle($markdown, $filePath);

        // Use normalized full path as slug to support sub-path URLs (e.g., "get-started/requirements")
        $slug = SlugHandleHelper::normalizePathSlug($filePath, '');
        if ($slug === '') {
            throw new \Exception("Normalized slug is empty for file path: {$filePath}");
        }

        // Create category key from section title (e.g., "Get Started" → "get-started")
        $categoryKey = SlugHandleHelper::normalizeSlug($category, '');

        // Find existing element or create new one
        $page = SourceDoc::find()
            ->sourceId($plugin->id)
            ->version($this->pageVersionValue($version))
            ->slug($slug)
            ->status(null)
            ->one();

        if (!$page) {
            // A soft-deleted page can still occupy the (sourceId, version, slug)
            // unique slot. Restore and reuse it instead of inserting a duplicate
            // (which would trip the unique index and abort the whole sync).
            $trashed = SourceDoc::find()
                ->sourceId($plugin->id)
                ->version($this->pageVersionValue($version))
                ->slug($slug)
                ->status(null)
                ->trashed(true)
                ->one();

            if ($trashed) {
                Craft::$app->getElements()->restoreElement($trashed);
                $page = $trashed;
            }
        }

        if (!$page) {
            $page = new SourceDoc();
            $page->sourceId = $plugin->id;
            $page->version = $this->pageVersionValue($version);
            $page->slug = $slug;
        }

        // Set the primary site for saving
        $page->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        // Update non-translatable data
        $page->category = $categoryKey;
        $page->version = $this->pageVersionValue($version);
        $page->order = $order;

        // Update translatable data
        $page->title = $title;
        $page->description = $parsed['frontmatter']['description'] ?? $this->extractDescription($markdown);
        $page->markdownSource = $markdown;
        $page->htmlContent = $parsed['html'];
        $page->headings = $parsed['headings'];
        $page->keywords = $parsed['frontmatter']['keywords'] ?? [];
        $page->lastSyncedAt = gmdate('Y-m-d H:i:s');

        if (!empty($parsed['frontmatter'])) {
            $page->metadata = $parsed['frontmatter'];
        }

        if (!Craft::$app->elements->saveElement($page)) {
            throw new \Exception('Failed to save doc page element: ' . implode(', ', $page->getErrorSummary(true)));
        }
    }

    /**
     * Extract title from markdown (first H1 heading)
     *
     * @param string $markdown Markdown content
     * @param string $fallbackPath Fallback path to generate title from
     * @return string Title
     */
    protected function extractTitle(string $markdown, string $fallbackPath): string
    {
        // Match first H1 heading
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
            $title = trim($matches[1]);

            // Strip @since(X.Y.Z) from title — version badges belong in the body, not navigation
            $title = preg_replace('/\s*@since\([^)]+\)/', '', $title);

            return trim($title);
        }

        // Fallback: convert filename to title
        $filename = basename($fallbackPath);
        return $this->handleToName($filename);
    }

    /**
     * Extract description from markdown (first paragraph after title)
     *
     * @param string $markdown Markdown content
     * @return string|null Description
     */
    protected function extractDescription(string $markdown): ?string
    {
        // Remove the title line
        $content = preg_replace('/^#\s+.+$/m', '', $markdown, 1);
        $content = trim($content);

        // Get first paragraph (non-heading, non-code text)
        $lines = explode("\n", $content);
        $paragraph = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines, headings, code blocks, lists
            if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '```') || str_starts_with($line, '-') || str_starts_with($line, '|')) {
                if ($paragraph !== '') {
                    break;
                }
                continue;
            }

            $paragraph .= $line . ' ';
        }

        $paragraph = trim($paragraph);

        if (mb_strlen($paragraph) > 160) {
            $paragraph = mb_substr($paragraph, 0, 157) . '...';
        }

        return $paragraph ?: null;
    }

    /**
     * Convert title to slug
     *
     * @param string $title Title
     * @return string Slug
     */
    protected function titleToSlug(string $title): string
    {
        return SlugHandleHelper::normalizeSlug($title, '');
    }

    /**
     * Remove orphan pages that are no longer in the sidebar
     *
     * @param int $sourceId Source record ID
     * @param string[] $syncedSlugs Slugs that were synced in this run
     */
    protected function cleanupOrphanPages(int $sourceId, string $version, array $syncedSlugs): void
    {
        $existingPages = SourceDoc::find()
            ->sourceId($sourceId)
            ->version($version)
            ->status(null)
            ->all();

        foreach ($existingPages as $page) {
            if (!in_array($page->slug, $syncedSlugs, true)) {
                Craft::$app->elements->deleteElement($page);
                $this->logInfo('Deleted orphan doc page', ['slug' => $page->slug, 'sourceId' => $sourceId, 'version' => $version]);
            }
        }
    }

    /**
     * Get or create plugin record
     *
     * @param string $handle Plugin handle
     * @return SourceRecord|null
     */
    protected function getOrCreatePlugin(string $handle): ?SourceRecord
    {
        $handle = SlugHandleHelper::normalizeSlug($handle, '');
        if ($handle === '') {
            return null;
        }

        $plugin = SourceRecord::findOne(['handle' => $handle]);
        if ($plugin) {
            return $plugin;
        }

        // Auto-register a local source straight from settings — but ONLY when the
        // handle resolves to a real plugin/module directory on disk (composer.json
        // present), so a typo'd handle never spawns a junk source. GitHub sources
        // must be onboarded in the CP; a repository URL can't be derived from a handle.
        $settings = DocsManager::getInstance()->getSettings();
        $basePath = (string) $settings->localPluginBasePath;
        if ($settings->defaultSourceType !== 'local' || $basePath === '') {
            return null;
        }

        $localPath = rtrim($basePath, '/\\') . '/' . $handle;
        $resolved = LocalSourcePathHelper::resolve($localPath);
        if (!is_dir($resolved) || !is_file($resolved . '/composer.json')) {
            return null;
        }

        $plugin = new SourceRecord();
        $plugin->handle = $handle;
        $plugin->name = $this->handleToName($handle);
        $plugin->kind = 'plugin';
        $plugin->sourceType = 'local';
        $plugin->localPath = $localPath;
        $plugin->enabled = true;

        if (!$plugin->save()) {
            $this->logError('Failed to auto-create source record', [
                'handle' => $handle,
                'errors' => $plugin->getErrors(),
            ]);
            return null;
        }

        $this->logInfo('Auto-registered local docs source from handle', [
            'handle' => $handle,
            'localPath' => $localPath,
        ]);

        return $plugin;
    }

    /**
     * Get plugin path based on source type
     *
     * @param SourceRecord $plugin Plugin record
     * @return string|null Resolved path
     */
    protected function getPluginPath(SourceRecord $plugin): ?string
    {
        $settings = DocsManager::getInstance()->getSettings();

        // Use localPath if set on plugin
        if ($plugin->localPath) {
            return LocalSourcePathHelper::resolve($plugin->localPath);
        }

        // Use settings basePath + handle
        if ($settings->localPluginBasePath) {
            return LocalSourcePathHelper::join((string) $settings->localPluginBasePath, $plugin->handle);
        }

        return null;
    }

    /**
     * Extract raw section from markdown (without parsing)
     *
     * @param string $markdown Full markdown
     * @param string $section Section heading
     * @return string Extracted section
     */
    protected function extractSectionRaw(string $markdown, string $section): string
    {
        $extracted = DocsManager::getInstance()->parser->extractSection($markdown, $section);
        return $extracted ?? $markdown;
    }

    /**
     * Convert plugin handle to display name
     *
     * @param string $handle Plugin handle (e.g., 'translation-manager')
     * @return string Display name (e.g., 'Translation Manager')
     */
    protected function handleToName(string $handle): string
    {
        // Convert kebab-case to Title Case
        $words = explode('-', $handle);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }

    /**
     * Sync all enabled plugins
     *
     * @return array Results per plugin
     */
    public function syncAllPlugins(): array
    {
        /** @var SourceRecord[] $plugins */
        $plugins = SourceRecord::find()->where(['enabled' => true])->all();
        $results = [];

        foreach ($plugins as $plugin) {
            $results[$plugin->handle] = $this->syncPlugin($plugin->handle);
        }

        return $results;
    }

    /**
     * Get sync statistics
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        // Get last sync from content table (has lastSyncedAt)
        $lastSyncRow = (new \craft\db\Query())
            ->from('{{%docsmanager_pages_content}}')
            ->orderBy(['lastSyncedAt' => SORT_DESC])
            ->limit(1)
            ->one();

        return [
            'totalPlugins' => SourceRecord::find()->count(),
            'enabledPlugins' => SourceRecord::find()->where(['enabled' => true])->count(),
            'totalPages' => SourceDoc::find()->status(null)->count(),
            'lastSync' => $lastSyncRow['lastSyncedAt'] ?? null,
        ];
    }

    /**
     * Fetch file from GitHub repository
     *
     * Build the base URL that relative `images/...` paths in markdown should rewrite to.
     *
     * Local source → local controller route (served from disk).
     * GitHub API source → raw.githubusercontent.com on the default branch.
     */
    protected function buildImageBaseUrl(SourceRecord $plugin, SourceVersionRecord $version): ?string
    {
        if ($plugin->sourceType === 'github-api') {
            if (!$plugin->repositoryUrl || !preg_match('#github\.com/([^/]+)/([^/]+)#', $plugin->repositoryUrl, $m)) {
                return null;
            }
            $owner = $m[1];
            $repo = rtrim($m[2], '/');
            return "https://raw.githubusercontent.com/{$owner}/{$repo}/{$version->ref}/docs/images/";
        }

        $versionSegment = $this->pageVersionValue($version) !== '' ? $this->pageVersionValue($version) . '/' : '';

        return '/plugins/' . $plugin->handle . '/docs/' . $versionSegment . 'images/';
    }

    protected function readLocalFile(string $pluginPath, string $filePath, SourceVersionRecord $version): ?string
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $filePath) === 1 || str_contains($filePath, "\0") || str_starts_with($filePath, '/')) {
            return null;
        }

        if ((bool) $version->isDefault) {
            $fullPath = $pluginPath . '/' . $filePath;
            if (!is_file($fullPath)) {
                return null;
            }

            $content = file_get_contents($fullPath);
            return is_string($content) ? $content : null;
        }

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $version->ref) !== 1) {
            return null;
        }

        $spec = $version->ref . ':' . $filePath;
        $command = sprintf('git -C %s show %s', escapeshellarg($pluginPath), escapeshellarg($spec));
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $content = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return $exitCode === 0 && is_string($content) ? $content : null;
    }

    /**
     * @param SourceRecord $plugin Plugin record with repositoryUrl
     * @param string $filePath Path to file (e.g., 'docs/index.json' or 'README.md')
     * @return array|string|null Decoded JSON array, raw content, or null on failure
     */
    protected function fetchGithubFile(SourceRecord $plugin, string $filePath, string $ref = 'main')
    {
        $settings = DocsManager::getInstance()->getSettings();
        $token = App::env($settings->githubToken);

        if (!$token) {
            throw new \Exception('GitHub token not configured');
        }

        // Extract owner/repo from repositoryUrl
        // e.g., https://github.com/LindemannRock/craft-translation-manager
        preg_match('#github\.com/([^/]+)/([^/]+)#', $plugin->repositoryUrl, $matches);
        if (!$matches) {
            throw new \Exception('Invalid repository URL');
        }

        $owner = $matches[1];
        $repo = $matches[2];

        // Fetch file from GitHub API
        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$filePath}?ref=" . rawurlencode($ref);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
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
            throw new \Exception("GitHub API error: {$httpCode} for {$filePath}");
        }

        $data = json_decode($response, true);

        // Content is base64 encoded
        $content = base64_decode($data['content'] ?? '');

        // If it's a JSON file, decode it
        if (str_ends_with($filePath, '.json')) {
            return json_decode($content, true);
        }

        // Return raw content for markdown files
        return $content;
    }

    protected function pageVersionValue(SourceVersionRecord $version): string
    {
        return $version->slug ?? '';
    }

    private function setSourceMetadataValue(SourceRecord $source, string $key, mixed $value): void
    {
        $metadata = [];
        if ($source->metadata !== null && $source->metadata !== '') {
            $decoded = json_decode($source->metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        if ($value === null || $value === '') {
            unset($metadata[$key]);
        } else {
            $metadata[$key] = $value;
        }

        $encoded = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $source->metadata = is_string($encoded) ? $encoded : null;
    }
}
