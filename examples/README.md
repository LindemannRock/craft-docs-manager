# Example Frontend Templates

This folder contains starter templates for displaying plugin documentation on your website.

## Installation

Copy the templates to your Craft project:

```bash
# Copy from plugin examples to your templates folder
cp -r plugins/plugin-docs/examples/templates/* templates/plugins/
```

## Files Included

### 1. `_layout.twig`
Base layout with header, footer, and responsive structure.

**Copy to:** `templates/plugins/_layout.twig`

**Customize:**
- Replace Tailwind CDN with your own CSS
- Update header navigation
- Update footer content
- Add your site branding

### 2. `plugin-index.twig`
Lists all your plugins in a grid.

**Copy to:** `templates/plugins/index.twig`

**URL:** `/plugins`

**What it displays:**
- All enabled plugins from database
- Plugin name, description, version
- Link to each plugin's docs

### 3. `plugin-detail.twig`
Plugin detail page showing documentation sections.

**Copy to:** `templates/plugins/_plugin.twig`

**URL:** `/plugins/{handle}` (e.g., `/plugins/translation-manager`)

**What it displays:**
- Plugin overview
- Documentation sections grouped by category
- Links to GitHub
- "Get Started" button

### 4. `doc-page.twig`
The main documentation page template.

**Copy to:** `templates/plugins/_doc.twig`

**URL:** `/plugins/{handle}/docs/{slug}` (e.g., `/plugins/translation-manager/docs/installation`)

**What it displays:**
- Left sidebar: Full navigation by category
- Main content: Parsed HTML from markdown
- Right sidebar: "On This Page" anchor links
- Previous/Next navigation at bottom

## Required Routes

Add these routes to `config/routes.php`:

```php
<?php
return [
    // Plugins index
    'plugins' => ['template' => 'plugins/index'],

    // Plugin detail
    'plugins/<handle:{slug}>' => ['template' => 'plugins/_plugin'],

    // Documentation page
    'plugins/<handle:{slug}>/docs/<slug:{slug}>' => ['template' => 'plugins/_doc'],
];
```

## Template Variables Reference

### In all templates:
- `siteName` - Your site name from general config

### In `plugin-detail.twig`:
```twig
plugin.id           - Plugin ID
plugin.name         - "Translation Manager"
plugin.handle       - "translation-manager"
plugin.description  - Plugin description
plugin.currentVersion - "5.0.9"
plugin.repositoryUrl - GitHub URL
```

### In `doc-page.twig`:
```twig
{# Plugin #}
plugin.name         - Plugin name
plugin.handle       - Plugin handle

{# Page #}
page.title          - "Installation & Setup"
page.description    - Meta description
page.htmlContent    - Parsed HTML (use |raw)
page.category       - "get-started"
page.slug           - "installation"
page.headings       - JSON array of H2/H3 headings

{# Headings structure #}
[
  {
    "level": 2,
    "text": "Installation",
    "anchor": "installation"
  },
  {
    "level": 3,
    "text": "Via Composer",
    "anchor": "via-composer"
  }
]
```

## Customization Tips

### Styling
Replace Tailwind CDN with your own CSS framework:
- Remove `<script src="https://cdn.tailwindcss.com"></script>`
- Add your own CSS files
- Update class names to match your styles

### Navigation Icons
Add icons to sidebar navigation groups (optional):
```twig
{% if group.icon %}
    <i class="icon-{{ group.icon }}"></i>
{% endif %}
{{ group.label }}
```

### Search Integration
Add search box to sidebar (future enhancement):
```twig
<input type="search"
       placeholder="Search docs..."
       class="w-full px-3 py-2 border rounded">
```

### Breadcrumbs
Add breadcrumb navigation:
```twig
<nav class="mb-4 text-sm">
    <a href="/plugins">Plugins</a>
    <span class="mx-2">/</span>
    <a href="/plugins/{{ handle }}">{{ plugin.name }}</a>
    <span class="mx-2">/</span>
    <span class="text-gray-600">{{ page.title }}</span>
</nav>
```

## Testing

After copying templates:

1. **Visit plugins index:**
   - http://your-site.test/plugins
   - Should show all synced plugins

2. **Visit plugin detail:**
   - http://your-site.test/plugins/translation-manager
   - Should show documentation sections

3. **Visit documentation page:**
   - http://your-site.test/plugins/translation-manager/docs/installation
   - Should show:
     - Left sidebar with full navigation
     - Main content with parsed markdown
     - Right sidebar with "On This Page" anchors
     - Previous/Next links at bottom

## Next Steps

Once basic templates are working:

1. **Add search** - Full-text search across all docs
2. **Add copy buttons** - Copy code blocks to clipboard
3. **Add syntax highlighting** - Prism.js or highlight.js
4. **Add version switcher** - View docs for different versions
5. **Mobile responsive** - Collapsible sidebars
6. **Dark mode** - Theme switcher

---

Need help with any customization? Check the Plugin Docs README.md or docs/DOCS-STRATEGY.md
