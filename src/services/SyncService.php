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

            // 3. Get sidebar structure (new format: .sidebar.json)
            $sidebarData = $this->loadSidebar($plugin, $pluginPath);
            if (!$sidebarData) {
                $results['errors'][] = "No .sidebar.json found";
                return $results;
            }

            // 4. Get plugin version
            if ($plugin->sourceType !== 'github-api') {
                $versionData = DocsManager::getInstance()->versionDetector->getPluginVersion($handle, $pluginPath);
            } else {
                $versionData = null;
            }

            if ($versionData) {
                $plugin->currentVersion = $versionData['version'];

                if ($versionData['releaseDate']) {
                    try {
                        $date = new \DateTime($versionData['releaseDate']);
                        $plugin->releaseDate = $date->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        $plugin->releaseDate = null;
                    }
                } else {
                    $plugin->releaseDate = null;
                }

                $results['version'] = $versionData['version'];
            }

            // 5. Sync changelog content
            try {
                if ($plugin->sourceType === 'github-api') {
                    $changelogContent = $this->fetchGithubFile($plugin, 'CHANGELOG.md');
                } else {
                    $changelogPath = $pluginPath . '/CHANGELOG.md';
                    $changelogContent = file_exists($changelogPath) ? file_get_contents($changelogPath) : null;
                }
                $plugin->changelogContent = $changelogContent ?: null;
            } catch (\Exception $e) {
                // Changelog is optional — don't fail the sync
                $this->logInfo('Changelog not found', ['handle' => $handle, 'error' => $e->getMessage()]);
            }

            // 6. Sync icon (src/icon.svg)
            try {
                if ($plugin->sourceType === 'github-api') {
                    $iconContent = $this->fetchGithubFile($plugin, 'src/icon.svg');
                    $plugin->iconSvg = is_string($iconContent) ? $iconContent : null;
                } else {
                    $iconPath = $pluginPath . '/src/icon.svg';
                    $plugin->iconSvg = file_exists($iconPath) ? file_get_contents($iconPath) : null;
                }
            } catch (\Exception) {
                $plugin->iconSvg = null;
            }

            // 7. Process each section from sidebar
            $syncedSlugs = [];
            $globalOrder = 0;
            foreach ($sidebarData as $sectionIndex => $section) {
                $sectionTitle = $section['title'] ?? 'Unknown';
                $children = $section['children'] ?? [];

                foreach ($children as $childIndex => $childPath) {
                    try {
                        $slug = SlugHandleHelper::normalizePathSlug((string) $childPath, '');
                        if ($slug === '') {
                            $results['errors'][] = "Failed to sync '{$childPath}': normalized slug is empty";
                            continue;
                        }
                        if (isset($syncedSlugs[$slug])) {
                            $results['errors'][] = "Failed to sync '{$childPath}': normalized slug '{$slug}' is duplicated in the sidebar";
                            continue;
                        }

                        $globalOrder++;
                        $this->syncPageFromFile($plugin, $pluginPath, $sectionTitle, $childPath, $globalOrder);
                        $syncedSlugs[$slug] = true;
                        $results['pages']++;
                    } catch (\Exception $e) {
                        $results['errors'][] = "Failed to sync '{$childPath}': {$e->getMessage()}";
                    }
                }
            }

            // 8. Cleanup orphan pages (pages no longer in sidebar)
            $this->cleanupOrphanPages($plugin->id, array_keys($syncedSlugs));

            // 9. Update source last synced time
            $plugin->lastSyncedAt = gmdate('Y-m-d H:i:s');
            $plugin->save();

            $results['success'] = true;
        } catch (\Exception $e) {
            $results['errors'][] = "Sync failed: {$e->getMessage()}";
            $this->logError('Failed to sync plugin', ['handle' => $handle, 'error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Load sidebar structure from .sidebar.json
     *
     * @param SourceRecord $plugin Plugin record
     * @param string $pluginPath Plugin path
     * @return array|null Sidebar data
     */
    protected function loadSidebar(SourceRecord $plugin, string $pluginPath): ?array
    {
        if ($plugin->sourceType === 'github-api') {
            return $this->fetchGithubFile($plugin, 'docs/.sidebar.json');
        }

        $sidebarPath = $pluginPath . '/docs/.sidebar.json';
        if (!file_exists($sidebarPath)) {
            return null;
        }

        return json_decode(file_get_contents($sidebarPath), true);
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
    protected function syncPageFromFile(SourceRecord $plugin, string $pluginPath, string $category, string $filePath, int $order): void
    {
        // Build full path to markdown file
        $fullPath = $pluginPath . '/docs/' . $filePath . '.md';

        if ($plugin->sourceType === 'github-api') {
            $markdown = $this->fetchGithubFile($plugin, 'docs/' . $filePath . '.md');
        } else {
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$fullPath}");
            }
            $markdown = file_get_contents($fullPath);
        }

        // Parse markdown
        $parsed = DocsManager::getInstance()->parser->parseMarkdown($markdown, null, true, $this->buildImageBaseUrl($plugin));

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
            ->slug($slug)
            ->status(null)
            ->one();

        if (!$page) {
            $page = new SourceDoc();
            $page->sourceId = $plugin->id;
            $page->slug = $slug;
        }

        // Set the primary site for saving
        $page->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        // Update non-translatable data
        $page->category = $categoryKey;
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

        if (strlen($paragraph) > 160) {
            $paragraph = substr($paragraph, 0, 157) . '...';
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
    protected function cleanupOrphanPages(int $sourceId, array $syncedSlugs): void
    {
        $existingPages = SourceDoc::find()
            ->sourceId($sourceId)
            ->status(null)
            ->all();

        foreach ($existingPages as $page) {
            if (!in_array($page->slug, $syncedSlugs, true)) {
                Craft::$app->elements->deleteElement($page);
                $this->logInfo('Deleted orphan doc page', ['slug' => $page->slug, 'sourceId' => $sourceId]);
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

        if (!$plugin) {
            // Try to auto-create from handle
            $plugin = new SourceRecord();
            $plugin->handle = $handle;
            $plugin->name = $this->handleToName($handle);
            $plugin->enabled = true;

            if (!$plugin->save()) {
                $this->logError('Failed to create plugin record', ['handle' => $handle]);
                return null;
            }
        }

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
    protected function buildImageBaseUrl(SourceRecord $plugin): ?string
    {
        if ($plugin->sourceType === 'github-api') {
            if (!$plugin->repositoryUrl || !preg_match('#github\.com/([^/]+)/([^/]+)#', $plugin->repositoryUrl, $m)) {
                return null;
            }
            $owner = $m[1];
            $repo = rtrim($m[2], '/');
            return "https://raw.githubusercontent.com/{$owner}/{$repo}/main/docs/images/";
        }

        return '/plugins/' . $plugin->handle . '/docs/images/';
    }

    /**
     * @param SourceRecord $plugin Plugin record with repositoryUrl
     * @param string $filePath Path to file (e.g., 'docs/index.json' or 'README.md')
     * @return array|string|null Decoded JSON array, raw content, or null on failure
     */
    protected function fetchGithubFile(SourceRecord $plugin, string $filePath)
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
        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$filePath}";

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
}
