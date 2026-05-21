# Source Doc Queries @since(5.0.0)

You can fetch synced documentation pages in your templates or PHP code using **source doc queries**.

## Creating a Query

In Twig, use the `craft.docsManager` variable methods (the recommended approach for frontend templates):

```twig
{% set pages = craft.docsManager.getPages('search-manager') %}
```

In PHP (modules, plugins, service classes):

```php
use lindemannrock\docsmanager\elements\SourceDoc;

$query = SourceDoc::find();
```

## Parameters @since(5.0.0)

### `sourceId()` @since(5.0.0)

Narrows query results to docs belonging to the given source ID or IDs.

```php
$pages = SourceDoc::find()->sourceId(1)->all();

// Multiple IDs
$pages = SourceDoc::find()->sourceId([1, 2])->all();
```

| Accepts | Description |
|---------|-------------|
| `int` | Single source ID |
| `int[]` | Array of source IDs |
| `null` | No filter (default) |

---

### `sourceHandle()` @since(5.0.0)

Narrows query results to docs belonging to the given source handle or handles. An inner join to `docsmanager_sources` is performed automatically.

```php
$pages = SourceDoc::find()->sourceHandle('search-manager')->all();

// Multiple handles
$pages = SourceDoc::find()->sourceHandle(['search-manager', 'translation-manager'])->all();
```

| Accepts | Description |
|---------|-------------|
| `string` | Single source handle |
| `string[]` | Array of source handles |
| `null` | No filter (default) |

---

### `category()` @since(5.0.0)

Narrows query results to docs with the given category key or keys. Categories correspond to the top-level sidebar sections (e.g., `get-started`, `feature-tour`, `developers`).

In Twig, use `craft.docsManager.getPages()` with the optional category parameter:

```twig
{% set pages = craft.docsManager.getPages('search-manager', 'get-started') %}
```

In PHP:

```php
$pages = SourceDoc::find()
    ->sourceHandle('search-manager')
    ->category('get-started')
    ->all();
```

| Accepts | Description |
|---------|-------------|
| `string` | Single category key |
| `string[]` | Array of category keys |
| `null` | No filter (default) |

---

### `slug()` @since(5.0.0)

Narrows query results to the doc with the given slug or slugs. Slugs are relative paths within the docs folder (e.g., `get-started/installation`, `developers/events`).

```php
$page = SourceDoc::find()
    ->sourceHandle('search-manager')
    ->slug('get-started/installation')
    ->one();
```

| Accepts | Description |
|---------|-------------|
| `string` | Single slug |
| `string[]` | Array of slugs |
| `null` | No filter (default) |

---

### `hasContent()` @since(5.0.0)

Narrows query results to docs that have parsed HTML content. Useful for filtering out placeholder pages that were registered but never synced.

```php
// Only pages with content
$pages = SourceDoc::find()->hasContent()->all();
```

| Accepts | Description |
|---------|-------------|
| `true` / no argument | Only docs with non-empty `htmlContent` |
| `null` | No filter (default) |

---

## Standard Query Parameters

`SourceDocQuery` extends Craft's base `ElementQuery`, so all standard Craft query parameters are available:

| Parameter | Description |
|-----------|-------------|
| `.status(null)` | Include all statuses (enabled and disabled) |
| `.orderBy(['docsmanager_pages.order' => SORT_ASC])` | Sort by sync order |
| `.limit(10)` | Limit results |
| `.offset(20)` | Paginate results |
| `.count()` | Count matching docs |
| `.ids()` | Return IDs only |

---

## SourceDoc Properties

Each `SourceDoc` element exposes the following properties:

| Property | Type | Description |
|----------|------|-------------|
| `sourceId` | `int\|null` | Parent source record ID |
| `slug` | `string\|null` | Page slug, e.g. `get-started/installation` |
| `category` | `string\|null` | Sidebar section key, e.g. `get-started` |
| `order` | `int` | Sort position within the source |
| `title` | `string\|null` | Page title (translatable) |
| `description` | `string\|null` | Short description (translatable) |
| `markdownSource` | `string\|null` | Raw markdown content (translatable) |
| `htmlContent` | `string\|null` | Parsed HTML content (translatable) |
| `headings` | `array\|null` | H2/H3 heading anchors for "On This Page" nav |
| `keywords` | `array\|null` | Search keyword tokens |
| `metadata` | `array\|null` | Frontmatter metadata from the markdown file |
| `lastSyncedAt` | `string\|null` | ISO 8601 timestamp of last successful sync |

### `getSourceHandle()` @since(5.0.0)

Returns the handle of the parent source for this doc. Result is cached per instance.

```twig
{{ page.sourceHandle }}
```

```php
$handle = $sourceDoc->getSourceHandle(); // e.g. 'search-manager'
```

---

## Custom Page Queries @since(5.0.0)

Custom pages (FAQ, Features, Support, etc.) use a separate element type and query class.

**Class:** `lindemannrock\docsmanager\elements\PluginPage`
**Query:** `lindemannrock\docsmanager\elements\db\PluginPageQuery`

```php
use lindemannrock\docsmanager\elements\PluginPage;

$pages = PluginPage::find()
    ->sourceHandle('search-manager')
    ->all();
```

### `sourceId()` @since(5.0.0)

Same behaviour as `SourceDocQuery::sourceId()` — narrows to the given source ID(s).

### `sourceHandle()` @since(5.0.0)

Same behaviour as `SourceDocQuery::sourceHandle()` — narrows to the given source handle(s).

### `pageType()` @since(5.0.0)

Narrows results to custom pages of the given type. Page types are configured per-source and correspond to color set keys (`features`, `faq`, `support`, `pricing`, `custom`).

```php
$faqPages = PluginPage::find()
    ->sourceHandle('search-manager')
    ->pageType('faq')
    ->all();
```

| Accepts | Description |
|---------|-------------|
| `string` | Single page type |
| `string[]` | Array of page types |
| `null` | No filter (default) |
