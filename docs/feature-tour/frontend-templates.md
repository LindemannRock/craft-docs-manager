# Frontend Templates

Docs Manager provides Twig variables for rendering documentation on your frontend and includes starter templates to get going quickly.

## Install Starter Templates

```bash title="PHP"
php craft docs-manager/templates/install
```

```bash title="DDEV"
ddev craft docs-manager/templates/install
```

This copies starter templates to `templates/plugins/` (or a path you enter when prompted) with:

- `index.twig` — source listing page
- `_plugin.twig` — source detail page with sub-navigation
- `_doc.twig` — documentation page with sidebar, content, and "On This Page" nav
- `_changelog.twig` — changelog page
- `_layout.twig` — base layout to customize with your site design

## URL Routing

Add these routes to `config/routes.php` for sub-path doc URLs:

```php
return [
    'plugins' => ['template' => 'plugins/index'],
    'plugins/<handle:{slug}>/docs/<category:{slug}>/<page:{slug}>' => ['template' => 'plugins/_doc'],
    'plugins/<handle:{slug}>/docs/<page:{slug}>' => ['template' => 'plugins/_doc'],
    'plugins/<handle:{slug}>/changelog' => ['template' => 'plugins/_changelog'],
    'plugins/<handle:{slug}>' => ['template' => 'plugins/_plugin'],
];
```

The two doc routes handle both nested paths (`/docs/get-started/installation`) and top-level pages (`/docs/troubleshooting`).

> [!NOTE]
> The `docs-manager/templates/install` command installs routes automatically, but it registers a simplified single-pattern doc route that does not match sub-path slugs containing slashes. If you run the installer, replace the generated doc route with the two-route pattern shown above to support nested paths.

## Template Examples

### List Sources

```twig
{% set sources = craft.docsManager.getSources() %}
{% set plugins = craft.docsManager.getPlugins() %}
{% set themes = craft.docsManager.getThemes() %}
```

### Single Source

```twig
{% set source = craft.docsManager.getSource('search-manager') %}
{% set plugin = craft.docsManager.getPlugin('search-manager') %}
{% set theme = craft.docsManager.getTheme('medical') %}
```

### Source Icon

```twig
{% if plugin.iconSvg %}
    <div class="plugin-icon">{{ plugin.iconSvg|raw }}</div>
{% endif %}
```

### Doc Page

```twig
{% set page = craft.docsManager.getPage('search-manager', 'get-started/installation') %}
{{ page.htmlContent|raw }}
```

### Sidebar Navigation

```twig
{% set nav = craft.docsManager.getNavigation('search-manager') %}
```

### Prev/Next Navigation

```twig
{% set prevNext = craft.docsManager.getPrevNextPages('search-manager', currentSlug) %}
```

See [Template Variables](../developers/template-variables.md) for the full API reference.
