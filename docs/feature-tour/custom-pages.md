# Custom Pages @since(5.0.0)

Custom pages are CP-managed content pages — FAQ, feature comparisons, support contacts, pricing information, or fully custom content — attached to a source. Unlike synced documentation pages (which come from markdown files), custom pages are created and edited directly in the Craft control panel.

## Page Types

Each custom page has a `pageType` that controls how it is categorized and badged in the CP.

| Type | Color | Use Case |
|------|-------|----------|
| `features` | Indigo | Feature overview or comparison |
| `faq` | Cyan | Frequently asked questions |
| `support` | Rose | Support contact, community links |
| `pricing` | Violet | Pricing tiers and plan comparison |
| `custom` | Lime | Any other page type |

## Managing Custom Pages

1. Go to **Docs Manager > Pages** in the CP
2. Click **New Page**
3. Select the parent source
4. Choose a page type
5. Set a title, slug, and sort order
6. Add content using the page's field layout
7. Click **Save**

## Permissions

Managing custom pages requires the `docsManager:managePages` permission, with granular `createPages`, `editPages`, and `deletePages` sub-permissions. See [Permissions](../developers/permissions.md) for details.

## Template Usage

Custom pages can be queried via `craft.docsManager.getCustomPages()` or directly via `PluginPage::find()`.

```twig
{% set pages = craft.docsManager.getCustomPages('search-manager') %}
{% for page in pages %}
    <h3>{{ page.title }}</h3>
    <span class="badge">{{ page.pageType }}</span>
{% endfor %}
```

> [!NOTE]
> Custom pages do not have their own URLs (`hasUris()` returns `false`). Render them inline or build custom routes based on the `slug` property.

For advanced querying, see [Source Doc Queries](../getting-elements/source-doc-queries.md#custom-page-queries).

## Field Layout

Custom pages support Craft's native field layout system. Go to **Docs Manager > Settings > Field Layout** to configure which fields appear on the custom page edit screen.

## Properties

Each `PluginPage` element exposes the following properties:

| Property | Type | Description |
|----------|------|-------------|
| `sourceId` | `int\|null` | Parent source record ID |
| `pageType` | `string\|null` | Page type key (`features`, `faq`, `support`, `pricing`, `custom`) |
| `slug` | `string\|null` | URL slug |
| `order` | `int` | Sort position within the source (default `0`) |

### Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `getSourceHandle()` | `string\|null` | Returns the handle of the parent source, or `null` if `sourceId` is not set |
