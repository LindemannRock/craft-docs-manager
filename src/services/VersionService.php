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
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Version Service
 *
 * Fetches plugin versions from various sources:
 * - GitHub releases
 * - Packagist
 * - Local git tags
 * - composer.json
 *
 * @since 5.0.0
 */
class VersionService extends Component
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('docs-manager');
    }

    /**
     * Get plugin version (tries multiple sources in priority order)
     *
     * @param string $handle Plugin handle (e.g., 'translation-manager')
     * @param string|null $localPath Local plugin path (optional)
     * @return array|null ['version' => '1.2.3', 'releaseDate' => '2025-10-27', 'source' => 'github']
     */
    public function getPluginVersion(string $handle, ?string $localPath = null): ?array
    {
        // 1. Try GitHub API
        $github = $this->getGitHubVersion($handle);
        if ($github) {
            return $github;
        }

        // 2. Try Packagist API
        $packagist = $this->getPackagistVersion($handle);
        if ($packagist) {
            return $packagist;
        }

        // 3. Try local git tags
        if ($localPath) {
            $git = $this->getLocalGitVersion($localPath);
            if ($git) {
                return $git;
            }
        }

        // 4. Fallback: composer.json
        if ($localPath) {
            $composer = $this->getComposerVersion($localPath);
            if ($composer) {
                return $composer;
            }
        }

        return null;
    }

    /**
     * Get version from GitHub releases
     *
     * @param string $handle Plugin handle
     * @return array|null ['version' => '1.2.3', 'releaseDate' => '2025-10-27', 'source' => 'github']
     */
    public function getGitHubVersion(string $handle): ?array
    {
        $url = "https://api.github.com/repos/LindemannRock/craft-{$handle}/releases/latest";

        try {
            $response = $this->fetchJson($url);

            if ($response && isset($response['tag_name'])) {
                return [
                    'version' => ltrim($response['tag_name'], 'v'), // Remove 'v' prefix
                    'releaseDate' => $response['published_at'] ?? null,
                    'source' => 'github',
                ];
            }
        } catch (\Exception $e) {
            $this->logWarning('GitHub API failed', ['handle' => $handle, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get version from Packagist
     *
     * @param string $handle Plugin handle
     * @return array|null ['version' => '1.2.3', 'releaseDate' => null, 'source' => 'packagist']
     */
    public function getPackagistVersion(string $handle): ?array
    {
        $url = "https://packagist.org/packages/lindemannrock/craft-{$handle}.json";

        try {
            $response = $this->fetchJson($url);

            if ($response && isset($response['package']['versions'])) {
                $versions = array_keys($response['package']['versions']);

                // Filter out dev versions and get latest stable
                $stableVersions = array_filter($versions, function($version) {
                    return !str_contains($version, 'dev') && preg_match('/^\d+\.\d+/', $version);
                });

                if (!empty($stableVersions)) {
                    // Sort versions
                    usort($stableVersions, 'version_compare');
                    $latestVersion = end($stableVersions);

                    return [
                        'version' => ltrim($latestVersion, 'v'),
                        'releaseDate' => null, // Packagist doesn't provide release dates easily
                        'source' => 'packagist',
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logWarning('Packagist API failed', ['handle' => $handle, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get version from local git tags
     *
     * @param string $path Path to plugin directory
     * @return array|null ['version' => '1.2.3', 'releaseDate' => null, 'source' => 'git']
     */
    public function getLocalGitVersion(string $path): ?array
    {
        $resolvedPath = Craft::getAlias($path);

        if (!is_dir($resolvedPath . '/.git')) {
            return null;
        }

        try {
            // Get latest tag
            $tag = trim(shell_exec("cd {$resolvedPath} && git describe --tags --abbrev=0 2>/dev/null"));

            if ($tag) {
                return [
                    'version' => ltrim($tag, 'v'),
                    'releaseDate' => null,
                    'source' => 'git',
                ];
            }
        } catch (\Exception $e) {
            $this->logWarning('Git command failed', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get version from composer.json
     *
     * @param string $path Path to plugin directory
     * @return array|null ['version' => '1.2.3', 'releaseDate' => null, 'source' => 'composer']
     */
    public function getComposerVersion(string $path): ?array
    {
        $resolvedPath = Craft::getAlias($path);
        $composerFile = $resolvedPath . '/composer.json';

        if (!file_exists($composerFile)) {
            return null;
        }

        try {
            $composer = json_decode(file_get_contents($composerFile), true);

            if (isset($composer['version']) && $composer['version'] !== 'dev-main') {
                return [
                    'version' => ltrim($composer['version'], 'v'),
                    'releaseDate' => null,
                    'source' => 'composer',
                ];
            }
        } catch (\Exception $e) {
            $this->logWarning('Failed to read composer.json', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetch JSON from URL
     *
     * @param string $url URL to fetch
     * @return array|null Decoded JSON or null on failure
     */
    protected function fetchJson(string $url): ?array
    {
        $settings = DocsManager::getInstance()->getSettings();

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: LindemannRock-Docs-Manager/1.0',
                    'Accept: application/json',
                ],
                'timeout' => 10,
            ],
        ];

        // Add GitHub token if configured
        if (str_contains($url, 'api.github.com') && $settings->githubToken) {
            $options['http']['header'][] = "Authorization: token {$settings->githubToken}";
        }

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }
}
