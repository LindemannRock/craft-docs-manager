<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\services;

use craft\base\Component;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Parser Service
 *
 * Handles all markdown parsing operations:
 * - Frontmatter extraction
 * - Markdown to HTML conversion
 * - Section extraction
 * - Heading extraction for anchors
 *
 * @since 5.0.0
 */
class ParserService extends Component
{
    use LoggingTrait;

    private static int $tabGroupCounter = 0;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('docs-manager');
    }

    /**
     * Parse frontmatter from markdown content
     *
     * @param string $markdown Markdown content with optional frontmatter
     * @return array ['frontmatter' => array, 'content' => string]
     */
    public function parseFrontmatter(string $markdown): array
    {
        $frontmatter = [];
        $content = $markdown;

        // Check if markdown starts with ---
        if (str_starts_with(trim($markdown), '---')) {
            // Extract frontmatter block
            $pattern = '/^---\s*\n(.*?)\n---\s*\n(.*)$/s';
            if (preg_match($pattern, $markdown, $matches)) {
                // Parse YAML frontmatter
                try {
                    $frontmatter = Yaml::parse($matches[1]) ?? [];
                    $content = $matches[2]; // Content after frontmatter
                } catch (\Exception $e) {
                    $this->logError('Failed to parse frontmatter', ['error' => $e->getMessage()]);
                }
            }
        }

        return [
            'frontmatter' => $frontmatter,
            'content' => trim($content),
        ];
    }

    /**
     * Convert markdown to HTML
     *
     * @param string $markdown Markdown content
     * @param bool $enableGfm Enable GitHub Flavored Markdown
     * @return string HTML output
     */
    public function markdownToHtml(string $markdown, bool $enableGfm = true): string
    {
        try {
            $settings = \lindemannrock\docsmanager\DocsManager::getInstance()->getSettings();

            // Create CommonMark environment
            $config = [
                'html_input' => 'strip', // Strip raw HTML for security
                'allow_unsafe_links' => false,
            ];

            // Add heading anchor configuration if enabled
            if ($settings->enableAnchorGeneration) {
                $config['heading_permalink'] = [
                    'html_class' => 'heading-permalink',
                    'id_prefix' => '',
                    'insert' => 'before',
                    'title' => 'Permalink',
                    'symbol' => '#',
                ];
            }

            $environment = new Environment($config);
            $environment->addExtension(new CommonMarkCoreExtension());

            if ($enableGfm) {
                $environment->addExtension(new GithubFlavoredMarkdownExtension());
            }

            // Add heading permalink extension if anchor generation is enabled
            if ($settings->enableAnchorGeneration) {
                $environment->addExtension(new \League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension());
            }

            $converter = new MarkdownConverter($environment);
            $html = $converter->convert($markdown)->getContent();

            // Don't highlight here - will be done in template during render
            // This ensures assets are registered when page is actually rendered
            return $html;
        } catch (\Exception $e) {
            $this->logError('Failed to convert markdown', ['error' => $e->getMessage()]);
            return '<p>Error parsing markdown</p>';
        }
    }

    /**
     * Extract a specific section from markdown
     *
     * @param string $markdown Full markdown content
     * @param string $sectionHeading Heading to extract (e.g., "Installation")
     * @return string|null Extracted section content or null if not found
     */
    public function extractSection(string $markdown, string $sectionHeading): ?string
    {
        // Match the heading and capture its level
        $pattern = '/^(#{1,3})\s+' . preg_quote($sectionHeading, '/') . '\s*$/m';

        if (preg_match($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            $headingLevel = strlen($matches[1][0]); // Count # symbols
            $startPos = $matches[0][1] + strlen($matches[0][0]); // Position after heading

            // Find next heading of same or higher level (but not inside code blocks)
            $remainingContent = substr($markdown, $startPos);
            $lines = explode("\n", $remainingContent);
            $inCodeBlock = false;
            $endLine = null;

            foreach ($lines as $lineNum => $line) {
                // Track code fence state
                if (preg_match('/^```/', $line)) {
                    $inCodeBlock = !$inCodeBlock;
                    continue;
                }

                // Skip lines inside code blocks
                if ($inCodeBlock) {
                    continue;
                }

                // Check if this line is a heading of same or higher level
                if (preg_match('/^#{1,' . $headingLevel . '}\s+/', $line)) {
                    $endLine = $lineNum;
                    break;
                }
            }

            // Extract content
            if ($endLine !== null) {
                $contentLines = array_slice($lines, 0, $endLine);
                $content = implode("\n", $contentLines);
            } else {
                // No next heading - take everything
                $content = $remainingContent;
            }

            // Return content WITHOUT the heading (template will render the title)
            return trim($content);
        }

        return null;
    }

    /**
     * Extract headings (H2-H6) from HTML for "On This Page" navigation and permalinks
     *
     * @param string $html HTML content
     * @return array Array of ['level' => 2, 'text' => 'Heading', 'anchor' => 'heading']
     */
    public function extractHeadings(string $html): array
    {
        $headings = [];

        // Match heading tags (H2-H6) — read id attribute set by addHeadingIds()
        $pattern = '/<h([2-6])\s[^>]*id="([^"]*)"[^>]*>(.*?)<\/h\1>/i';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $level = (int) $match[1];
                $anchor = $match[2];
                // Strip since badges from heading text for "On This Page" nav
                $cleanContent = preg_replace('/<span class="docs-since">[^<]*<\/span>/', '', $match[3]);
                $text = trim(strip_tags($cleanContent));

                $headings[] = [
                    'level' => $level,
                    'text' => $text,
                    'anchor' => $anchor,
                ];
            }
        }

        return $headings;
    }

    /**
     * Generate URL-safe anchor from heading text
     *
     * @param string $text Heading text
     * @return string Anchor ID
     */
    public function generateAnchor(string $text): string
    {
        // Convert to lowercase
        $anchor = strtolower($text);

        // Replace spaces and special chars with hyphens
        $anchor = preg_replace('/[^a-z0-9]+/', '-', $anchor);

        // Remove leading/trailing hyphens
        $anchor = trim($anchor, '-');

        return $anchor;
    }

    /**
     * Add ID attributes to headings (H2-H6) in HTML for anchor linking and permalinks
     *
     * @param string $html HTML content
     * @return string HTML with heading IDs added
     */
    public function addHeadingIds(string $html): string
    {
        $seenIds = [];

        // Add IDs to heading tags (H2-H6) — H1 is stripped and rendered separately as page title
        $html = preg_replace_callback(
            '/<h([2-6])([^>]*)>(.*?)<\/h\1>/i',
            function($matches) use (&$seenIds) {
                $level = $matches[1];
                $attributes = $matches[2];
                $content = $matches[3];

                // Check if ID already exists
                if (str_contains($attributes, 'id=')) {
                    return $matches[0]; // Already has ID
                }

                // Strip since badges entirely before generating anchor ID
                $cleanContent = preg_replace('/<span class="docs-since">[^<]*<\/span>/', '', $content);
                $text = trim(strip_tags($cleanContent));
                $id = $this->generateAnchor($text);

                // Deduplicate: append -2, -3, etc. for repeated headings
                if (isset($seenIds[$id])) {
                    $seenIds[$id]++;
                    $id .= '-' . $seenIds[$id];
                } else {
                    $seenIds[$id] = 1;
                }

                return "<h{$level} id=\"{$id}\"{$attributes}>{$content}</h{$level}>";
            },
            $html
        );

        return $html;
    }

    /**
     * Parse markdown file completely
     *
     * Combines frontmatter parsing, markdown conversion, and heading extraction
     *
     * @param string $markdown Full markdown content
     * @param string|null $section Optional section to extract
     * @param bool $stripFirstH1 Strip the first H1 heading (title is shown via template)
     * @return array ['frontmatter' => array, 'html' => string, 'headings' => array]
     */
    public function parseMarkdown(string $markdown, ?string $section = null, bool $stripFirstH1 = true, ?string $imageBaseUrl = null): array
    {
        // 1. Parse frontmatter
        $parsed = $this->parseFrontmatter($markdown);
        $frontmatter = $parsed['frontmatter'];
        $content = $parsed['content'];

        // 2. Extract section if specified
        if ($section) {
            $extracted = $this->extractSection($content, $section);
            if ($extracted) {
                $content = $extracted;
            } else {
                $this->logWarning('Section not found in markdown', ['section' => $section]);
            }
        }

        // 3. Strip first H1 heading if enabled (to avoid duplicate title in template)
        if ($stripFirstH1) {
            $content = $this->stripFirstH1($content);
        }

        // 4. Extract tabbed code groups before CommonMark (tokens replace groups)
        $tabResult = $this->extractCodeTabs($content);
        $content = $tabResult['markdown'];

        // 5. Convert markdown to HTML
        $html = $this->markdownToHtml($content);

        // 6. Replace tab tokens with rendered tab HTML
        $html = $this->replaceCodeTabTokens($html, $tabResult['tabGroups']);

        // 7. Convert @since(X.Y.Z) shortcodes to version badges
        $html = $this->convertSinceBadges($html);

        // 8. Convert GFM-style alerts (> [!TIP]) to semantic callouts
        $html = $this->convertAlertCallouts($html);

        // 9. Strip .md extension from relative documentation links
        $html = preg_replace('/(<a\s[^>]*href="(?!https?:\/\/)[^"]*?)\.md(["#])/i', '$1$2', $html);

        // 9a. Rewrite relative image paths (images/...) to the provided base URL
        if ($imageBaseUrl !== null && $imageBaseUrl !== '') {
            $base = rtrim($imageBaseUrl, '/') . '/';
            $html = preg_replace(
                '/(<img\s[^>]*src=")(?!https?:\/\/|\/|data:)images\//i',
                '$1' . $base,
                $html
            );
        }

        // 10. Open external links in new tab
        $html = $this->addExternalLinkAttributes($html);

        // 11. Add heading IDs for anchor linking
        $html = $this->addHeadingIds($html);

        // 12. Extract headings for navigation
        $headings = $this->extractHeadings($html);

        return [
            'frontmatter' => $frontmatter,
            'html' => $html,
            'headings' => $headings,
        ];
    }

    /**
     * Extract groups of consecutive titled code fences from markdown
     *
     * Detects consecutive code fences with `title="..."` in the info string
     * and replaces them with placeholder tokens. The tokens are later replaced
     * with tabbed code block HTML after CommonMark conversion.
     *
     * Markdown syntax: ```bash title="Composer"
     *
     * @param string $markdown Markdown content
     * @return array{markdown: string, tabGroups: array<string, array>}
     */
    private function extractCodeTabs(string $markdown): array
    {
        $tabGroups = [];

        // Match individual titled code fences: ```lang title="Title"\ncontent\n```
        $pattern = '/```(\w+)\s+title="([^"]+)"\n(.*?)\n```/s';

        // Find all matches with positions
        if (!preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return ['markdown' => $markdown, 'tabGroups' => []];
        }

        // Group consecutive matches (only whitespace between them)
        $groups = [];
        $currentGroup = [];

        foreach ($matches as $match) {
            if ($currentGroup === []) {
                $currentGroup[] = $match;
            } else {
                $prevMatch = $currentGroup[count($currentGroup) - 1];
                $prevEnd = $prevMatch[0][1] + strlen($prevMatch[0][0]);
                $thisStart = $match[0][1];
                $between = substr($markdown, $prevEnd, $thisStart - $prevEnd);

                if (trim($between) === '') {
                    $currentGroup[] = $match;
                } else {
                    if (count($currentGroup) >= 2) {
                        $groups[] = $currentGroup;
                    }
                    $currentGroup = [$match];
                }
            }
        }
        if (count($currentGroup) >= 2) {
            $groups[] = $currentGroup;
        }

        if ($groups === []) {
            return ['markdown' => $markdown, 'tabGroups' => []];
        }

        // Replace groups with tokens (reverse order to preserve offsets)
        foreach (array_reverse($groups) as $group) {
            $id = 'CODETABGROUP' . count($tabGroups);
            $tabs = [];
            foreach ($group as $match) {
                $tabs[] = [
                    'language' => $match[1][0],
                    'title' => $match[2][0],
                    'content' => $match[3][0],
                ];
            }
            $tabGroups[$id] = $tabs;

            $start = $group[0][0][1];
            $lastMatch = $group[count($group) - 1];
            $end = $lastMatch[0][1] + strlen($lastMatch[0][0]);

            $markdown = substr_replace($markdown, "\n\n" . $id . "\n\n", $start, $end - $start);
        }

        return ['markdown' => $markdown, 'tabGroups' => $tabGroups];
    }

    /**
     * Replace code tab tokens in HTML with rendered tab components
     *
     * @param string $html HTML content with tokens
     * @param array<string, array> $tabGroups Tab group data keyed by token
     * @return string HTML with tab components
     */
    private function replaceCodeTabTokens(string $html, array $tabGroups): string
    {
        foreach ($tabGroups as $id => $tabs) {
            $tabHtml = $this->renderCodeTabs($tabs);
            // CommonMark wraps the token in a <p> tag
            $html = str_replace("<p>{$id}</p>", $tabHtml, $html);
        }

        return $html;
    }

    /**
     * Render a group of code tabs as HTML
     *
     * @param array $tabs Array of ['language' => string, 'title' => string, 'content' => string]
     * @return string Tab component HTML
     */
    private function renderCodeTabs(array $tabs): string
    {
        $groupId = self::$tabGroupCounter++;
        $buttons = '';
        $panels = '';

        foreach ($tabs as $i => $tab) {
            $isFirst = $i === 0;
            $title = htmlspecialchars($tab['title'], ENT_QUOTES);
            $content = htmlspecialchars($tab['content'], ENT_QUOTES);
            $lang = htmlspecialchars($tab['language'], ENT_QUOTES);
            $dataTitle = htmlspecialchars(strtolower($tab['title']), ENT_QUOTES);
            $tabId = 'ct-' . $groupId . '-tab-' . $i;
            $panelId = 'ct-' . $groupId . '-panel-' . $i;

            $buttons .= '<button type="button" role="tab" class="code-tab-btn"'
                . ' id="' . $tabId . '"'
                . ' aria-selected="' . ($isFirst ? 'true' : 'false') . '"'
                . ' aria-controls="' . $panelId . '"'
                . ' tabindex="' . ($isFirst ? '0' : '-1') . '"'
                . ' data-title="' . $dataTitle . '"'
                . '>' . $title . '</button>';

            $panels .= '<div role="tabpanel" class="code-tab-panel"'
                . ' id="' . $panelId . '"'
                . ' aria-labelledby="' . $tabId . '"'
                . ' aria-hidden="' . ($isFirst ? 'false' : 'true') . '"'
                . '><pre><code class="language-' . $lang . '">' . $content . '</code></pre></div>';
        }

        return '<div class="code-tabs">'
            . '<div class="code-tab-buttons" role="tablist">' . $buttons . '</div>'
            . $panels
            . '</div>';
    }

    /**
     * Convert @since(X.Y.Z) shortcodes to version badge spans
     *
     * Handles three contexts:
     * - Inline in headings: `<h2>someMethod() @since(5.6.0)</h2>`
     * - Heading: `<h2>Method @since(5.6.0)</h2>` → wrapped in `.docs-heading-group`
     * - Standalone paragraph: `<p>@since(5.6.0)</p>` → `.docs-since-block`
     * - Inline in text/table cells: `... @since(5.6.0) ...` → `<span>`
     *
     * @param string $html HTML content
     * @return string HTML with since badges
     */
    protected function convertSinceBadges(string $html): string
    {
        // 1. Headings: extract @since from <hN> and wrap heading + badge in a group
        $html = preg_replace(
            '/<(h[1-6])([^>]*)>(.*?)\s*@since\(([^)]+)\)\s*<\/\1>/i',
            '<div class="docs-heading-group"><$1$2>$3</$1><span class="docs-since" role="note" aria-label="Added in version $4">v$4</span></div>',
            $html,
        );

        // 2. Standalone paragraph: <p>@since(X.Y.Z)</p> → block badge
        $html = preg_replace(
            '/<p>\s*@since\(([^)]+)\)\s*<\/p>/',
            '<div class="docs-since-block"><span class="docs-since" role="note" aria-label="Added in version $1">v$1</span></div>',
            $html,
        );

        // 3. Inline (table cells, paragraphs): @since(X.Y.Z) → <span>
        $html = preg_replace(
            '/@since\(([^)]+)\)/',
            '<span class="docs-since" role="note" aria-label="Added in version $1">v$1</span>',
            $html,
        );

        return $html;
    }

    /**
     * Convert GFM-style alert blockquotes to semantic callout elements
     *
     * Transforms blockquotes starting with [!TYPE] markers into
     * accessible `<aside>` elements with proper ARIA attributes.
     *
     * Supported types: NOTE, TIP, WARNING, CAUTION, IMPORTANT
     *
     * Input:  `<blockquote><p>[!TIP]\nSome text</p></blockquote>`
     * Output: `<aside class="docs-callout docs-callout--tip" role="note" aria-label="Tip"><p>Some text</p></aside>`
     *
     * @param string $html HTML content
     * @return string HTML with callout elements
     */
    protected function convertAlertCallouts(string $html): string
    {
        $types = ['NOTE', 'TIP', 'WARNING', 'CAUTION', 'IMPORTANT'];
        $typesPattern = implode('|', $types);

        // Match blockquotes where the first <p> starts with [!TYPE]
        // CommonMark renders: <blockquote>\n<p>[!TIP]\ntext</p>\n</blockquote>
        $pattern = '/<blockquote>\s*<p>\[!(' . $typesPattern . ')\]\s*\n?(.*?)<\/p>(.*?)<\/blockquote>/is';

        $html = preg_replace_callback($pattern, function(array $matches): string {
            $type = strtolower($matches[1]);
            $label = ucfirst($type);
            $firstParagraphContent = trim($matches[2]);
            $remainingContent = trim($matches[3]);

            $inner = '';
            if ($firstParagraphContent !== '') {
                $inner .= '<p>' . $firstParagraphContent . '</p>';
            }
            if ($remainingContent !== '') {
                $inner .= "\n" . $remainingContent;
            }

            return '<aside class="docs-callout docs-callout--' . $type . '" role="note" aria-label="' . $label . '">'
                . "\n" . $inner . "\n"
                . '</aside>';
        }, $html);

        return $html;
    }

    /**
     * Add target="_blank" and rel="noopener" to external links
     *
     * External links are those with href starting with http:// or https://.
     * Skips links that already have a target attribute.
     *
     * @param string $html HTML content
     * @return string HTML with external link attributes
     */
    protected function addExternalLinkAttributes(string $html): string
    {
        return preg_replace_callback(
            '/<a\s([^>]*href="https?:\/\/[^"]*"[^>]*)>/i',
            function(array $matches): string {
                $attrs = $matches[1];

                // Skip if target is already set
                if (preg_match('/\btarget\s*=/i', $attrs)) {
                    return $matches[0];
                }

                return '<a ' . $attrs . ' target="_blank" rel="noopener">';
            },
            $html,
        );
    }

    /**
     * Strip the first H1 heading from markdown content
     *
     * @param string $markdown Markdown content
     * @return string Markdown without first H1
     */
    protected function stripFirstH1(string $markdown): string
    {
        // Remove first line that starts with single # (H1)
        return preg_replace('/^#\s+.+$/m', '', $markdown, 1);
    }
}
