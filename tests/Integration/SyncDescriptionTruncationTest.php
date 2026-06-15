<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\services\SyncService;
use lindemannrock\docsmanager\tests\TestCase;

/**
 * Pins multibyte-safe truncation of the auto-generated page description.
 *
 * `extractDescription()` takes the first body paragraph of a doc page and
 * caps it at ~160 characters for the `description` column. The cap MUST be
 * character-based (`mb_substr`), not byte-based (`substr`): a byte cut that
 * lands inside a multibyte character (e.g. an em-dash, 3 bytes) leaves a
 * dangling byte, producing invalid UTF-8 that the utf8mb4 column rejects with
 * "Incorrect string value: '\xE2...'". That failure blocked the entire sync
 * for any plugin whose opener sentence had a multibyte char near byte 157.
 *
 * `extractDescription()` is `protected`; per the no-reflection convention we
 * extend with an anonymous subclass that re-exposes it.
 *
 * @since 5.1.0
 */
final class SyncDescriptionTruncationTest extends TestCase
{
    private SyncService $publicSync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicSync = new class () extends SyncService {
            public function extractDescriptionPublic(string $markdown): ?string
            {
                return $this->extractDescription($markdown);
            }
        };
    }

    public function testTruncationDoesNotSplitMultibyteCharacter(): void
    {
        // 156 ASCII chars, then an em-dash exactly at the truncation boundary,
        // then enough text to push the paragraph past the 160-char cap. A
        // byte-based substr(0, 157) would slice the em-dash (E2 80 94) after
        // its first byte; mb_substr(0, 157) keeps it whole.
        $markdown = str_repeat('A', 156) . '— and then some trailing words to exceed the one hundred and sixty character description cap comfortably.';

        $out = $this->publicSync->extractDescriptionPublic($markdown);

        $this->assertNotNull($out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'Description must remain valid UTF-8 after truncation');
        $this->assertStringEndsWith('...', $out);
        $this->assertStringContainsString('—', $out, 'The em-dash at the boundary must survive intact');
        // 157 characters of content + the literal ellipsis.
        $this->assertSame(160, mb_strlen($out));
    }

    public function testShortParagraphIsReturnedUntruncated(): void
    {
        $markdown = 'A short opener — with an em-dash — well under the cap.';

        $out = $this->publicSync->extractDescriptionPublic($markdown);

        $this->assertSame($markdown, $out);
        $this->assertStringEndsNotWith('...', $out);
    }
}
