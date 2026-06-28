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
 * Pins titled code-fence tab extraction in the full markdown parser.
 *
 * @since 5.4.0
 */
final class ParserCodeTabsTest extends TestCase
{
    public function testTopLevelTitledFencesRenderAsCodeTabs(): void
    {
        $markdown = <<<'MD'
```bash title="PHP"
php craft plugin/list
```

```bash title="DDEV"
ddev craft plugin/list
```
MD;

        $result = $this->parser->parseMarkdown($markdown, null, false);
        $html = $result['html'];

        $this->assertStringContainsString('<div class="code-tabs">', $html);
        $this->assertSame(2, substr_count($html, 'class="code-tab-btn"'));
        $this->assertStringContainsString('data-title="php"', $html);
        $this->assertStringContainsString('data-title="ddev"', $html);
        $this->assertStringContainsString('php craft plugin/list', $html);
        $this->assertStringContainsString('ddev craft plugin/list', $html);
        $this->assertStringNotContainsString('CODETABGROUP', $html);
    }

    public function testTitledFencesNestedInOrderedListStayInsideListItem(): void
    {
        $markdown = <<<'MD'
1. **Is the plugin installed?**

   ```bash title="PHP"
   php craft plugin/list
   ```

   ```bash title="DDEV"
   ddev craft plugin/list
   ```
MD;

        $result = $this->parser->parseMarkdown($markdown, null, false);
        $html = $result['html'];

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<strong>Is the plugin installed?</strong>', $html);
        $this->assertStringContainsString('<div class="code-tabs">', $html);
        $this->assertMatchesRegularExpression('/<li>.*<div class="code-tabs">.*<\/li>/s', $html);
        $this->assertStringNotContainsString('<p><strong>1. Is the plugin installed?', $html);
        $this->assertStringNotContainsString('CODETABGROUP', $html);
        $this->assertStringContainsString('php craft plugin/list', $html);
        $this->assertStringContainsString('ddev craft plugin/list', $html);
    }
}
