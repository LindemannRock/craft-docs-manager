<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\tests\TestCase;

/**
 * Pins the anchor-id + "On This Page" navigation pipeline.
 *
 * Three cooperating methods:
 *  - `generateAnchor()` — slugify a heading's text
 *  - `addHeadingIds()`  — inject `id="…"` on H2-H6 (never H1; H1 is the page
 *                         title rendered by the template). Dedupes repeats
 *                         with -2, -3 suffixes so two `## Options` sections
 *                         each get a deep-linkable id.
 *  - `extractHeadings()` — read the now-id'd headings into the TOC array
 *                          the docs template renders as the right-rail nav.
 *
 * Regressions break deep links across every plugin's docs site.
 *
 * @since 5.1.0
 */
final class ParserHeadingsAndAnchorsTest extends TestCase
{
    public function testGenerateAnchorLowercasesAndCollapsesSpecialsToHyphens(): void
    {
        // Single rule with three branches:
        //  - case-fold
        //  - collapse any run of non-[a-z0-9] into ONE hyphen
        //  - trim leading/trailing hyphens
        $this->assertSame('plain-text', $this->parser->generateAnchor('Plain Text'));
        $this->assertSame('method-name', $this->parser->generateAnchor('method() — Name?'));
        $this->assertSame('leading-trailing', $this->parser->generateAnchor('!!Leading & Trailing!!'));
        // Pure punctuation collapses to empty after trim — current contract.
        $this->assertSame('', $this->parser->generateAnchor('???'));
    }

    public function testAddHeadingIdsSkipsH1AndIdsH2ThroughH6(): void
    {
        $html = '<h1>Page Title</h1>'
            . '<h2>Section A</h2>'
            . '<h3>Subsection</h3>'
            . '<h4>Detail</h4>'
            . '<h5>Note</h5>'
            . '<h6>Smaller Note</h6>';

        $out = $this->parser->addHeadingIds($html);

        // H1 is the page title (rendered separately by the template) — must
        // stay un-id'd so it doesn't double up with the template's title.
        $this->assertStringContainsString('<h1>Page Title</h1>', $out);
        $this->assertStringContainsString('<h2 id="section-a">Section A</h2>', $out);
        $this->assertStringContainsString('<h3 id="subsection">Subsection</h3>', $out);
        $this->assertStringContainsString('<h4 id="detail">Detail</h4>', $out);
        $this->assertStringContainsString('<h5 id="note">Note</h5>', $out);
        $this->assertStringContainsString('<h6 id="smaller-note">Smaller Note</h6>', $out);
    }

    public function testAddHeadingIdsPreservesExistingIdsAndDedupesNewIdentical(): void
    {
        $html = '<h2 id="manual-anchor">Already Anchored</h2>'
            . '<h2>Options</h2>'
            . '<h2>Options</h2>'
            . '<h2>Options</h2>';

        $out = $this->parser->addHeadingIds($html);

        // Existing id: untouched (author chose the deep-link slug deliberately).
        $this->assertStringContainsString('<h2 id="manual-anchor">Already Anchored</h2>', $out);

        // Repeated text gets -2, -3 suffixes so each heading is independently
        // deep-linkable — bare `options` is left alone, NOT `options-1`.
        $this->assertStringContainsString('<h2 id="options">Options</h2>', $out);
        $this->assertStringContainsString('<h2 id="options-2">Options</h2>', $out);
        $this->assertStringContainsString('<h2 id="options-3">Options</h2>', $out);
    }

    public function testExtractHeadingsReturnsLevelTextAndAnchorForH2ThroughH6Only(): void
    {
        // Realistic post-pipeline input: H1 carries an id (added elsewhere), H2-H6
        // were id'd by addHeadingIds. extractHeadings() must ONLY return H2-H6
        // because the page title (H1) shouldn't appear in the right-rail TOC.
        $html = '<h1 id="page">Page Title</h1>'
            . '<h2 id="install">Installation</h2>'
            . '<h3 id="steps">Steps</h3>'
            . '<h4 id="step-1">Step 1</h4>'
            . '<h5 id="caveats">Caveats</h5>'
            . '<h6 id="footnote">Footnote</h6>';

        $headings = $this->parser->extractHeadings($html);

        $this->assertCount(5, $headings, 'H1 must be excluded — page title is rendered separately');
        $this->assertSame(['level' => 2, 'text' => 'Installation', 'anchor' => 'install'], $headings[0]);
        $this->assertSame(['level' => 3, 'text' => 'Steps', 'anchor' => 'steps'], $headings[1]);
        $this->assertSame(['level' => 4, 'text' => 'Step 1', 'anchor' => 'step-1'], $headings[2]);
        $this->assertSame(['level' => 5, 'text' => 'Caveats', 'anchor' => 'caveats'], $headings[3]);
        $this->assertSame(['level' => 6, 'text' => 'Footnote', 'anchor' => 'footnote'], $headings[4]);
    }
}
