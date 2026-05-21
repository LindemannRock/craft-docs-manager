<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\twigextensions;

use Craft;
use lindemannrock\codehighlighter\traits\CodeHighlighterTrait;
use lindemannrock\docsmanager\DocsManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig Extension for Docs Manager
 *
 * @since 5.0.0
 */
class DocsManagerTwigExtension extends AbstractExtension
{
    use CodeHighlighterTrait;

    public function getName(): string
    {
        return 'Docs Manager';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('applyCodeHighlighting', [$this, 'applyCodeHighlighting'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Apply code highlighting to documentation HTML during render
     * This processes code blocks and calls code-highlighter's PrismService
     * which properly registers all assets
     */
    public function applyCodeHighlighting(string $html): string
    {
        $settings = DocsManager::getInstance()->getSettings();

        // If highlighting is disabled, return original HTML
        if (!$settings->enableSyntaxHighlighting) {
            return $html;
        }

        // Check if code-highlighter plugin is available
        if (!$this->isCodeHighlighterAvailable()) {
            Craft::warning('Code highlighting enabled but code-highlighter plugin is not installed', 'docs-manager');
            return $html;
        }

        // Set theme from docs-manager settings
        $this->applyCodeTheme($settings->codeTheme);

        // Use DOMDocument to parse HTML and find code blocks
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $codeBlocks = $xpath->query('//pre/code');

        if ($codeBlocks->length === 0) {
            return $html;
        }

        // Process each code block
        foreach ($codeBlocks as $codeBlock) {
            if (!$codeBlock instanceof \DOMElement) {
                continue;
            }

            // Extract language from class attribute
            $language = 'markup';
            $classes = $codeBlock->getAttribute('class');
            if (preg_match('/language-(\w+)/', $classes, $matches)) {
                $language = $matches[1];
            }

            // Get the code content
            $code = $codeBlock->textContent;

            // Prepare options from docs-manager settings
            // Font size/family are omitted — controlled via CSS variables (--code-font-size, --code-font-family)
            $options = [
                'lineNumbers' => $settings->codeShowLineNumbers,
                'showCopy' => $settings->codeEnableCopyButton,
            ];

            try {
                $highlightedHtml = $this->highlightCode($code, $language, $options);

                // Replace the <pre><code> block with highlighted version
                $fragment = $dom->createDocumentFragment();
                $fragment->appendXML($highlightedHtml);

                if ($codeBlock->parentNode && $codeBlock->parentNode->parentNode) {
                    $codeBlock->parentNode->parentNode->replaceChild($fragment, $codeBlock->parentNode);
                }
            } catch (\Exception $e) {
                Craft::warning('Failed to highlight code block: ' . $e->getMessage(), 'docs-manager');
            }
        }

        // Return modified HTML
        $html = $dom->saveHTML();
        $html = preg_replace('/^<\?xml encoding="utf-8" \?>/', '', $html);

        return $html;
    }
}
