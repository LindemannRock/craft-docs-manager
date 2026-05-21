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
 * Pins the README/changelog metadata-extraction primitives that consumers
 * (ReadmeParserService, ChangelogService, SyncService) build on top of:
 *
 *  - `parseFrontmatter()` — `---\nYAML\n---\nbody` → ['frontmatter', 'content']
 *  - `extractSection()`   — pull `## Installation` (or any heading) out of a
 *                           README without absorbing later sections OR text
 *                           that looks like a heading but lives inside a
 *                           fenced code block.
 *
 * The code-fence awareness in `extractSection()` matters: README snippets
 * routinely embed Markdown examples like ` ```md\n## Step 1\n``` ` which
 * the extractor must NOT treat as a section boundary.
 *
 * @since 5.1.0
 */
final class ParserSectionAndFrontmatterTest extends TestCase
{
    public function testParseFrontmatterExtractsYamlAndReturnsTrimmedContent(): void
    {
        $markdown = "---\ntitle: Quickstart\ncategory: get-started\norder: 1\n---\n\n# Quickstart\n\nBody copy here.\n";

        $result = $this->parser->parseFrontmatter($markdown);

        $this->assertSame(['title' => 'Quickstart', 'category' => 'get-started', 'order' => 1], $result['frontmatter']);
        // Content is trimmed — sync writes this verbatim into the DocPageContent
        // row, so leading/trailing whitespace would creep into the rendered HTML.
        $this->assertSame("# Quickstart\n\nBody copy here.", $result['content']);
    }

    public function testParseFrontmatterReturnsEmptyArrayWhenNoFrontmatterBlockPresent(): void
    {
        // No `---` opener → frontmatter is empty, full markdown is the body.
        // Pins that the regex doesn't accidentally consume content for files
        // that just happen to contain a `---` somewhere in their body.
        $markdown = "# Quickstart\n\nBody copy here.\n";

        $result = $this->parser->parseFrontmatter($markdown);

        $this->assertSame([], $result['frontmatter']);
        $this->assertSame("# Quickstart\n\nBody copy here.", $result['content']);
    }

    public function testExtractSectionReturnsContentBetweenSameLevelHeadings(): void
    {
        $markdown = "# README\n\n## Installation\n\nRun composer require.\n\nThen activate the plugin.\n\n## Configuration\n\nEdit config/foo.php.\n";

        $section = $this->parser->extractSection($markdown, 'Installation');

        // Section body is returned WITHOUT the heading line itself (template
        // renders the title) and stops at the next H2 (same or higher level).
        $this->assertSame("Run composer require.\n\nThen activate the plugin.", $section);
    }

    public function testExtractSectionIgnoresHeadingsInsideFencedCodeBlocks(): void
    {
        // A `## Step 1` inside a fenced code block must NOT terminate the
        // "Installation" section — that would chop the code example in half.
        $markdown = "## Installation\n\nFollow these steps:\n\n```md\n## Step 1\nThis is example markdown.\n```\n\nFinal line of installation.\n\n## Configuration\n\nOther stuff.\n";

        $section = $this->parser->extractSection($markdown, 'Installation');

        $this->assertNotNull($section);
        $this->assertStringContainsString('Follow these steps:', $section);
        $this->assertStringContainsString('## Step 1', $section, 'Fenced code-block heading must round-trip intact');
        $this->assertStringContainsString('Final line of installation.', $section);
        $this->assertStringNotContainsString('## Configuration', $section, 'Real H2 boundary must terminate the section');
        $this->assertStringNotContainsString('Other stuff', $section);
    }
}
