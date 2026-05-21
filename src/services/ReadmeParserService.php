<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\services;

use craft\helpers\FileHelper;
use yii\base\Component;

/**
 * README Parser Service
 *
 * Parses README.md and extracts sections into structured documentation files.
 *
 * @since 5.0.0
 */
class ReadmeParserService extends Component
{
    /**
     * Section mapping: README heading → doc file path
     */
    private array $sectionMapping = [
        // Feature Tour
        'Features' => 'feature-tour/overview.md',
        'Multi-Site Translation Support' => 'feature-tour/multi-site.md',
        'Multi-Site Management' => 'feature-tour/multi-site.md',
        'Custom Short Domain' => 'feature-tour/custom-domain.md',
        'Comprehensive Analytics' => 'feature-tour/analytics.md',
        'QR Code Generation' => 'feature-tour/qr-codes.md',
        'Advanced Features' => 'feature-tour/advanced.md',
        'Advanced Backup System' => 'feature-tour/backups.md',

        // Get Started
        'Requirements' => 'get-started/requirements.md',
        'Installation' => 'get-started/installation.md',
        'Configuration' => 'get-started/configuration.md',

        // Template Guides
        'Usage' => 'template-guides/usage.md',
        'Site Translations' => 'template-guides/site-translations.md',
        'Managing Translations' => 'template-guides/managing-translations.md',
        'CSV Export' => 'template-guides/csv-export.md',
        'CSV Import' => 'template-guides/csv-import.md',
        'PHP File Export' => 'template-guides/php-export.md',

        // Integrations
        'Formie Integration' => 'integrations/formie.md',
        'Redirect Manager Integration' => 'integrations/redirect-manager.md',
        'SEOmatic Integration' => 'integrations/seomatic.md',

        // Developers
        'Permissions' => 'developers/permissions.md',
        'Console Commands' => 'developers/console-commands.md',
        'Logging' => 'developers/logging.md',
        'Security' => 'developers/security.md',
        'Events' => 'developers/events.md',
        'API Reference' => 'developers/api-reference.md',
        'Twig Variable' => 'developers/template-variables.md',

        // Other
        'Troubleshooting' => 'resources/troubleshooting.md',
        'Support' => null, // Skip
        'License' => null, // Skip
        'Credits' => null, // Skip
    ];

    /**
     * Existing uppercase files to consolidate
     */
    private array $uppercaseFiles = [
        'LOGGING.md' => 'developers/logging.md',
        'CONFIGURATION.md' => 'get-started/configuration.md',
        'BACKUPS.md' => 'feature-tour/backups.md',
        'TROUBLESHOOTING.md' => 'resources/troubleshooting.md',
    ];

    /**
     * Parse README and extract sections
     *
     * @param string $readmePath Path to README.md
     * @return array Array of sections with heading, level, content, and mapped path
     */
    public function parseReadme(string $readmePath): array
    {
        if (!file_exists($readmePath)) {
            return [];
        }

        $content = file_get_contents($readmePath);
        $sections = [];

        // Split by headings (## or ###)
        // Match: ## Heading or ### Heading
        $pattern = '/^(#{2,3})\s+(.+?)$/m';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        $headingCount = count($matches[0]);

        for ($i = 0; $i < $headingCount; $i++) {
            $level = strlen($matches[1][$i][0]); // 2 for ##, 3 for ###
            $heading = trim($matches[2][$i][0]);
            $startPos = $matches[0][$i][1];

            // Find content until next heading or end
            if ($i < $headingCount - 1) {
                $endPos = $matches[0][$i + 1][1];
            } else {
                $endPos = strlen($content);
            }

            // Extract content (skip the heading line itself)
            $headingLine = $matches[0][$i][0];
            $contentStart = $startPos + strlen($headingLine);
            $sectionContent = substr($content, $contentStart, $endPos - $contentStart);
            $sectionContent = trim($sectionContent);

            // Clean heading for mapping (remove special chars)
            $cleanHeading = $this->cleanHeading($heading);

            // Find mapped path
            $mappedPath = $this->findMappedPath($cleanHeading);

            $sections[] = [
                'heading' => $heading,
                'cleanHeading' => $cleanHeading,
                'level' => $level,
                'content' => $sectionContent,
                'mappedPath' => $mappedPath,
            ];
        }

        return $sections;
    }

    /**
     * Clean heading for mapping lookup
     */
    private function cleanHeading(string $heading): string
    {
        // Remove emojis and special characters
        $heading = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $heading);
        $heading = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $heading);
        $heading = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $heading);
        $heading = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $heading);
        $heading = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $heading);

        // Remove "(Optional)" suffix
        $heading = preg_replace('/\s*\(Optional\)\s*$/i', '', $heading);

        return trim($heading);
    }

    /**
     * Find mapped path for a heading
     */
    private function findMappedPath(string $heading): ?string
    {
        // Exact match
        if (isset($this->sectionMapping[$heading])) {
            return $this->sectionMapping[$heading];
        }

        // Partial match (heading contains mapping key)
        foreach ($this->sectionMapping as $key => $path) {
            if (stripos($heading, $key) !== false) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Migrate README and existing docs to new structure
     *
     * @param string $pluginPath Plugin root path
     * @param bool $dryRun If true, only return what would be done
     * @return array Results of migration
     */
    public function migrate(string $pluginPath, bool $dryRun = false): array
    {
        $results = [
            'parsed' => [],
            'created' => [],
            'consolidated' => [],
            'skipped' => [],
            'errors' => [],
        ];

        $readmePath = $pluginPath . '/README.md';
        $docsPath = $pluginPath . '/docs';

        // 1. Parse README
        $sections = $this->parseReadme($readmePath);
        $results['parsed'] = array_map(fn($s) => [
            'heading' => $s['heading'],
            'mappedPath' => $s['mappedPath'],
            'contentLength' => strlen($s['content']),
        ], $sections);

        // 2. Consolidate existing uppercase files first
        foreach ($this->uppercaseFiles as $oldFile => $newPath) {
            $oldPath = $docsPath . '/' . $oldFile;
            $newFullPath = $docsPath . '/' . $newPath;

            if (file_exists($oldPath)) {
                if ($dryRun) {
                    $results['consolidated'][] = "$oldFile → $newPath";
                } else {
                    // Read old content
                    $oldContent = file_get_contents($oldPath);

                    // Create new file (if doesn't exist, use old content)
                    if (!file_exists($newFullPath)) {
                        FileHelper::createDirectory(dirname($newFullPath));
                        file_put_contents($newFullPath, $oldContent);
                        $results['consolidated'][] = "$oldFile → $newPath (created)";
                    } else {
                        $results['skipped'][] = "$oldFile → $newPath (target exists)";
                    }

                    // Delete old file
                    unlink($oldPath);
                }
            }
        }

        // 3. Create doc files from README sections
        foreach ($sections as $section) {
            if ($section['mappedPath'] === null) {
                $results['skipped'][] = $section['heading'] . ' (no mapping)';
                continue;
            }

            if (empty($section['content'])) {
                $results['skipped'][] = $section['heading'] . ' (empty content)';
                continue;
            }

            $filePath = $docsPath . '/' . $section['mappedPath'];

            // Skip if file already exists (from consolidation or manual)
            if (file_exists($filePath)) {
                $results['skipped'][] = $section['heading'] . ' → ' . $section['mappedPath'] . ' (exists)';
                continue;
            }

            if ($dryRun) {
                $results['created'][] = $section['heading'] . ' → ' . $section['mappedPath'];
            } else {
                FileHelper::createDirectory(dirname($filePath));

                // Create markdown with heading
                $markdown = "# {$section['heading']}\n\n{$section['content']}\n";
                file_put_contents($filePath, $markdown);

                $results['created'][] = $section['heading'] . ' → ' . $section['mappedPath'];
            }
        }

        return $results;
    }

    /**
     * Get section mapping configuration
     */
    public function getSectionMapping(): array
    {
        return $this->sectionMapping;
    }

    /**
     * Get uppercase files configuration
     */
    public function getUppercaseFiles(): array
    {
        return $this->uppercaseFiles;
    }
}
