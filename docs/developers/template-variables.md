# Template Variables @since(5.0.0)

Docs Manager provides Twig variables for use in your templates via `craft.docsManager`.

## `craft.docsManager` @since(5.0.0)

### `getSources()` @since(5.0.0)

Get all enabled sources, optionally filtered by kind.

```twig
{# All enabled sources #}
{% set sources = craft.docsManager.getSources() %}

{# Only plugins #}
{% set plugins = craft.docsManager.getSources('plugin') %}

{# Only themes #}
{% set themes = craft.docsManager.getSources('theme') %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `kind` | `string\|null` | Filter by kind: `'plugin'`, `'theme'`, or `null` for all |

**Returns:** `array`

---

### `getPlugins()` @since(5.0.0)

Get all enabled plugin sources. Shorthand for `getSources('plugin')`.

```twig
{% set plugins = craft.docsManager.getPlugins() %}
```

**Returns:** `array`

---

### `getThemes()` @since(5.0.0)

Get all enabled theme sources. Shorthand for `getSources('theme')`.

```twig
{% set themes = craft.docsManager.getThemes() %}
```

**Returns:** `array`

---

### `getSource()` @since(5.0.0)

Get a single source by handle (any kind).

```twig
{% set source = craft.docsManager.getSource('search-manager') %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |

**Returns:** `array|null`

---

### `getPlugin()` @since(5.0.0)

Get a single plugin source by handle.

```twig
{% set plugin = craft.docsManager.getPlugin('search-manager') %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |

**Returns:** `array|null`

---

### `getTheme()` @since(5.0.0)

Get a single theme source by handle.

```twig
{% set theme = craft.docsManager.getTheme('medical') %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |

**Returns:** `array|null`

---

### `getPages()` @since(5.0.0)

Get documentation pages for a source.

```twig
{% set pages = craft.docsManager.getPages('search-manager') %}
{% set pages = craft.docsManager.getPages('search-manager', 'get-started') %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |
| `category` | `string\|null` | Optional category filter |

**Returns:** `SourceDoc[]`

---

### `getPage()` @since(5.0.0)

Get a single documentation page by source handle and slug.

```twig
{% set page = craft.docsManager.getPage('search-manager', 'get-started/installation') %}
{{ page.htmlContent|raw }}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |
| `slug` | `string` | Page slug |

**Returns:** `SourceDoc|null`

---

### `getCustomPages()` @since(5.0.0)

Get custom pages for a source.

```twig
{% set pages = craft.docsManager.getCustomPages('search-manager') %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |

**Returns:** `PluginPage[]`

---

### `getNavigation()` @since(5.0.0)

Get navigation structure from `.sidebar.json`.

```twig
{% set nav = craft.docsManager.getNavigation('search-manager') %}
{% for key, section in nav %}
    <h3>{{ section.label }}</h3>
    {% for page in section.pages %}
        <a href="/plugins/search-manager/docs/{{ page.slug }}">{{ page.title }}</a>
    {% endfor %}
{% endfor %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |

**Returns:** `array|null`

---

### `getAnchors()` @since(5.0.0)

Get page anchors (headings) for "On This Page" navigation.

```twig
{% set anchors = craft.docsManager.getAnchors(page) %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | `SourceDoc` | Page element |

**Returns:** `array`

---

### `getPrevNextPages()` @since(5.0.0)

Get previous and next pages for sequential navigation.

```twig
{% set prevNext = craft.docsManager.getPrevNextPages('search-manager', currentSlug) %}
{% if prevNext.prev %}
    <a href="...">{{ prevNext.prev.title }}</a>
{% endif %}
{% if prevNext.next %}
    <a href="...">{{ prevNext.next.title }}</a>
{% endif %}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |
| `currentSlug` | `string` | Current page slug |

**Returns:** `array{prev: SourceDoc|null, next: SourceDoc|null}`

---

### `getChangelog()` @since(5.0.0)

Get parsed changelog for a source.

```twig
{% set changelog = craft.docsManager.getChangelog('search-manager') %}
{{ changelog.latest.version }}
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `handle` | `string` | Source handle |

**Returns:** `array|null`

---

### `getStats()` @since(5.0.0)

Get sync statistics across all sources.

```twig
{% set stats = craft.docsManager.getStats() %}
```

**Returns:** `array`

---

### `getSettings()` @since(5.0.0)

Get plugin settings.

```twig
{% set settings = craft.docsManager.getSettings() %}
```

**Returns:** `\lindemannrock\docsmanager\models\Settings`

---

## Twig Filters

### `|applyCodeHighlighting` @since(5.0.0)

Applies Prism-based syntax highlighting to pre-parsed documentation HTML. Call this filter after outputting `page.htmlContent` to activate the Code Highlighter integration.

```twig
{{ page.htmlContent|applyCodeHighlighting|raw }}
```

The filter processes every `<pre><code>` block in the HTML, delegates to the [Code Highlighter](https://github.com/LindemannRock/craft-code-highlighter) plugin's `PrismService`, and registers the required frontend assets. Code blocks without a language class are treated as `markup`.

**Behavior when highlighting is disabled or unavailable:**

- If `enableSyntaxHighlighting` is `false` in settings, the HTML is returned unchanged.
- If the Code Highlighter plugin is not installed, the HTML is returned unchanged and a Craft warning is logged.

**Options applied from settings:**

| Setting | Passed to Code Highlighter |
|---------|---------------------------|
| `codeTheme` | Theme slug |
| `codeShowLineNumbers` | Line numbers on/off |
| `codeEnableCopyButton` | Copy button on/off |

`codeFontSize` and `codeFontFamily` are not passed directly — they are applied via CSS variables (`--code-font-size`, `--code-font-family`) on the frontend.

See [Configuration](../get-started/configuration.md#code-highlighting) for all code highlighting settings.
