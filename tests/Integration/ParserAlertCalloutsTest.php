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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the GFM-style alert blockquote → semantic `<aside>` callout conversion.
 *
 * Input shape comes out of CommonMark for `> [!TYPE]\nbody`:
 *     <blockquote><p>[!TYPE]\nbody</p></blockquote>
 *
 * Output is a semantic, ARIA-labelled aside the docs theme styles distinctly
 * per type (info/warning/danger). Bugs here either lose the callout (rendered
 * as a vanilla blockquote with a stray "[!TIP]" marker visible to readers) or
 * leak the marker into the body text.
 *
 * `convertAlertCallouts()` is `protected`; per the no-reflection convention
 * we extend with an anonymous subclass that re-exposes it.
 *
 * @since 5.1.0
 */
final class ParserAlertCalloutsTest extends TestCase
{
    private ParserService $publicParser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicParser = new class () extends ParserService {
            public function convertAlertCalloutsPublic(string $html): string
            {
                return $this->convertAlertCallouts($html);
            }
        };
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function alertTypeProvider(): array
    {
        // [TYPE_MARKER, css_class_suffix, aria_label]
        return [
            'NOTE'      => ['NOTE', 'note', 'Note'],
            'TIP'       => ['TIP', 'tip', 'Tip'],
            'WARNING'   => ['WARNING', 'warning', 'Warning'],
            'CAUTION'   => ['CAUTION', 'caution', 'Caution'],
            'IMPORTANT' => ['IMPORTANT', 'important', 'Important'],
        ];
    }

    #[DataProvider('alertTypeProvider')]
    public function testEachAlertTypeProducesItsOwnAside(
        string $marker,
        string $cssSuffix,
        string $ariaLabel,
    ): void {
        $html = "<blockquote><p>[!{$marker}]\nUse this advice.</p></blockquote>";

        $out = $this->publicParser->convertAlertCalloutsPublic($html);

        // Pin shape: <aside> wrapper with double class + role="note" + aria-label,
        // body wrapped in <p>. CSS suffix is lowercased; aria-label is ucfirst.
        $this->assertStringContainsString(
            "<aside class=\"docs-callout docs-callout--{$cssSuffix}\" role=\"note\" aria-label=\"{$ariaLabel}\">",
            $out,
        );
        $this->assertStringContainsString('<p>Use this advice.</p>', $out);
        $this->assertStringNotContainsString('[!' . $marker . ']', $out, 'Marker must be stripped from rendered body');
        $this->assertStringNotContainsString('<blockquote>', $out, 'Blockquote wrapper is replaced wholesale');
    }

    public function testAlertCalloutPreservesAdditionalParagraphsInsideBlockquote(): void
    {
        // CommonMark renders multi-paragraph blockquotes with the trailing
        // <p>...</p> tags inside the same <blockquote>. The pattern's third
        // capture group ($remainingContent) holds those — they must round-trip
        // intact so authors can write multi-paragraph TIPs.
        $html = "<blockquote><p>[!WARNING]\nFirst paragraph.</p>\n<p>Second paragraph.</p></blockquote>";

        $out = $this->publicParser->convertAlertCalloutsPublic($html);

        $this->assertStringContainsString('docs-callout--warning', $out);
        $this->assertStringContainsString('<p>First paragraph.</p>', $out);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $out);
    }

    public function testBlockquoteWithoutMarkerIsLeftUntouched(): void
    {
        // Regular blockquotes (author quoting prose) must not be converted —
        // only those whose first <p> opens with [!TYPE].
        $html = '<blockquote><p>A regular quote, no marker.</p></blockquote>';

        $out = $this->publicParser->convertAlertCalloutsPublic($html);

        $this->assertSame($html, $out);
    }
}
