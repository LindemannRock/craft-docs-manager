<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\services;

use craft\base\Component;
use League\CommonMark\CommonMarkConverter;
use lindemannrock\docsmanager\records\SourceRecord;

/**
 * Changelog Service
 *
 * Parses CHANGELOG.md files following the "Keep a Changelog" format
 *
 * @since 5.0.0
 */
class ChangelogService extends Component
{
    /**
     * Parse CHANGELOG.md file
     *
     * @param string $handle Plugin handle
     * @return array|null Parsed changelog data
     */
    public function parseChangelog(string $handle): ?array
    {
        // Read changelog from DB (synced from local or GitHub)
        $plugin = SourceRecord::find()->where(['handle' => $handle])->one();
        if (!$plugin || empty($plugin->changelogContent)) {
            return null;
        }

        $releases = $this->parseReleases($plugin->changelogContent);

        if (empty($releases)) {
            return null;
        }

        return [
            'releases' => $releases,
            'latest' => $releases[0] ?? null,
        ];
    }

    /**
     * Parse releases from changelog content
     *
     * @param string $content CHANGELOG.md content
     * @return array Array of releases
     */
    protected function parseReleases(string $content): array
    {
        $releases = [];
        $lines = explode("\n", $content);
        $currentRelease = null;
        $currentSection = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Match release header formats:
            // ## [1.2.3] - 2025-10-27
            // ## [1.2.3](link) (2025-10-27)
            // ## 1.2.3 - 2025-10-27
            if (preg_match('/^##\s+\[?(\d+\.\d+\.\d+)\]?.*?\(?(\d{4}-\d{2}-\d{2})\)?/i', $line, $matches)) {
                // Save previous release
                if ($currentRelease) {
                    $currentRelease['stats'] = $this->calculateStats($currentRelease);
                    $releases[] = $currentRelease;
                }

                // Start new release
                $currentRelease = [
                    'version' => $matches[1],
                    'date' => $matches[2],
                    'added' => [],
                    'improved' => [],
                    'changed' => [],
                    'fixed' => [],
                    'removed' => [],
                    'deprecated' => [],
                    'security' => [],
                ];
                $currentSection = null;
            }
            // Match section headers: ### Added, ### Fixed, etc.
            elseif (preg_match('/^###\s+(.+)$/i', $line, $matches)) {
                $sectionName = strtolower($matches[1]);
                $currentSection = $sectionName;
            }
            // Match list items: - Item text
            elseif ($currentRelease && $currentSection && preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
                $item = trim($matches[1]);

                // Format the item
                $item = $this->formatChangelogItem($item);

                // Map section names to arrays
                if (in_array($currentSection, ['added', 'new', 'features'])) {
                    $currentRelease['added'][] = $item;
                } elseif (in_array($currentSection, ['improved', 'changed', 'enhancement', 'enhancements'])) {
                    $currentRelease['improved'][] = $item;
                } elseif (in_array($currentSection, ['fixed', 'bug fixes', 'bugfixes'])) {
                    $currentRelease['fixed'][] = $item;
                } elseif ($currentSection === 'removed') {
                    $currentRelease['removed'][] = $item;
                } elseif ($currentSection === 'deprecated') {
                    $currentRelease['deprecated'][] = $item;
                } elseif ($currentSection === 'security') {
                    $currentRelease['security'][] = $item;
                }
            }
        }

        // Save last release
        if ($currentRelease) {
            $currentRelease['stats'] = $this->calculateStats($currentRelease);
            $releases[] = $currentRelease;
        }

        // Filter out empty releases (no changes)
        $releases = array_filter($releases, function($release) {
            return $release['stats']['total'] > 0;
        });

        // Re-index array after filtering
        return array_values($releases);
    }

    /**
     * Calculate stats for a release
     *
     * @param array $release Release data
     * @return array Stats
     */
    protected function calculateStats(array $release): array
    {
        return [
            'added' => count($release['added']),
            'improved' => count($release['improved']) + count($release['changed']),
            'fixed' => count($release['fixed']),
            'removed' => count($release['removed']),
            'total' => count($release['added']) + count($release['improved']) +
                      count($release['changed']) + count($release['fixed']) +
                      count($release['removed']),
        ];
    }

    /**
     * Format a changelog item
     *
     * - Capitalizes first letter
     * - Converts markdown links to HTML
     * - Shortens commit hashes
     *
     * @param string $item Raw changelog item
     * @return string Formatted item
     */
    protected function formatChangelogItem(string $item): string
    {
        // Capitalize first letter
        $item = ucfirst($item);

        // Shorten commit hashes before CommonMark parses the links:
        // ([abc123f](url)) → (abc123f)
        $item = preg_replace(
            '/\(?\[([a-f0-9]{7})[a-f0-9]*\]\(([^)]+)\)\)?/',
            '[$1]($2)',
            $item
        );

        // Parse all markdown (bold, italic, code, links, etc.) via CommonMark
        $converter = new CommonMarkConverter(['html_input' => 'strip']);
        $html = $converter->convert($item)->getContent();

        // Strip wrapping <p> tag from single-line output
        $html = preg_replace('#^<p>(.*)</p>\s*$#s', '$1', $html);

        return $html;
    }
}
