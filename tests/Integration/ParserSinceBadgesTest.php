<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\services\ParserService;
use lindemannrock\docsmanager\tests\TestCase;

/**
 * Pins the contract for the `@since(X.Y.Z)` shortcode → version-badge
 * conversion in the docs pipeline.
 *
 * Three contexts the parser must distinguish, in this exact precedence
 * (each pass runs in order and the next pass only sees what survived):
 *  1. Heading whose @since is the LAST thing before the closing tag →
 *     wrapped in `.docs-heading-group` with the badge alongside the heading.
 *  2. Standalone paragraph `<p>@since(X)</p>` → `.docs-since-block` block badge.
 *  3. Anything else (table cell, inline body text, heading where @since isn't
 *     trailing) → inline `<span class="docs-since">…</span>`.
 *
 * Regressions here ship a broken docs site to every plugin at once — the
 * since-badge is how every public method/property/setting is dated.
 *
 * `convertSinceBadges()` is `protected`; per the no-reflection convention
 * we extend with an anonymous subclass that re-exposes it.
 *
 * @since 5.1.0
 */
final class ParserSinceBadgesTest extends TestCase
{
    private ParserService $publicParser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicParser = new class () extends ParserService {
            public function convertSinceBadgesPublic(string $html): string
            {
                return $this->convertSinceBadges($html);
            }
        };
    }

    public function testHeadingWithTrailingSinceIsWrappedInDocsHeadingGroup(): void
    {
        $html = '<h2>someMethod() @since(5.6.0)</h2>';

        $out = $this->publicParser->convertSinceBadgesPublic($html);

        // Heading regex moves the badge OUT of the <h2> and wraps both in a
        // .docs-heading-group div — keeps the heading text clean for both
        // anchor-id generation and the "On This Page" nav.
        $this->assertStringContainsString('<div class="docs-heading-group">', $out);
        $this->assertStringContainsString('<h2>someMethod()</h2>', $out);
        $this->assertStringContainsString(
            '<span class="docs-since" role="note" aria-label="Added in version 5.6.0">v5.6.0</span>',
            $out,
        );
        // The badge must NOT remain inside the <h2> — otherwise extractHeadings
        // sees it as part of the heading text.
        $this->assertDoesNotMatchRegularExpression('/<h2>[^<]*@since/', $out);
        $this->assertStringNotContainsString('@since(5.6.0)', $out, '@since shortcode must be fully consumed');
    }

    public function testStandaloneParagraphSinceBecomesBlockBadge(): void
    {
        $html = '<p>@since(5.6.0)</p>';

        $out = $this->publicParser->convertSinceBadgesPublic($html);

        // A line containing only the shortcode renders as a top-of-page
        // "Added in version" block — distinct shape from inline use because
        // it carries a different visual treatment in the docs theme.
        $this->assertStringContainsString('<div class="docs-since-block">', $out);
        $this->assertStringContainsString(
            '<span class="docs-since" role="note" aria-label="Added in version 5.6.0">v5.6.0</span>',
            $out,
        );
        $this->assertStringNotContainsString('<p>@since', $out, 'Paragraph wrapper must be replaced');
    }

    public function testInlineSinceInTableCellBecomesSpan(): void
    {
        $html = '<td>API @since(5.6.0) added</td>';

        $out = $this->publicParser->convertSinceBadgesPublic($html);

        // Fallthrough pass — anything not matched by passes 1+2 becomes an
        // inline span. Pins that the table cell wrapper is untouched.
        $this->assertSame(
            '<td>API <span class="docs-since" role="note" aria-label="Added in version 5.6.0">v5.6.0</span> added</td>',
            $out,
        );
    }

    public function testMultipleShortcodesInOnePassAreAllConverted(): void
    {
        $html = '<h2>methodA() @since(5.6.0)</h2>'
            . '<p>Some text with @since(5.1.0) and @since(5.2.0) inline.</p>';

        $out = $this->publicParser->convertSinceBadgesPublic($html);

        // Pin: every shortcode is consumed regardless of context mix. A bug
        // where the regex stops after the first match would leave a literal
        // @since() in the output and ship raw to readers.
        $this->assertStringNotContainsString('@since(', $out);
        $this->assertSame(3, substr_count($out, '<span class="docs-since"'));
        $this->assertStringContainsString('v5.6.0</span>', $out);
        $this->assertStringContainsString('v5.1.0</span>', $out);
        $this->assertStringContainsString('v5.2.0</span>', $out);
    }
}
