# Twig Globals

Docs Manager registers a global Twig variable named `docsManagerHelper` via its `PluginNameExtension` Twig extension, which is initialized during plugin bootstrap. It is available in all CP templates rendered while Docs Manager is active.

## `docsManagerHelper` @since(5.0.0)

The helper is an instance of `PluginNameHelper` — a proxy to the plugin's Settings model that exposes name variants as Twig-friendly properties.

### Properties

| Property | Returns (default `pluginName = 'Docs Manager'`) | Description |
|----------|--------------------------------------------------|-------------|
| `docsManagerHelper.fullName` | `'Docs Manager'` | Full plugin name as configured |
| `docsManagerHelper.displayName` | `'Docs'` | Singular name, "Manager" stripped |
| `docsManagerHelper.pluralDisplayName` | `'Docs'` | Plural form of the display name |
| `docsManagerHelper.lowerDisplayName` | `'docs'` | Lowercase singular display name |
| `docsManagerHelper.pluralLowerDisplayName` | `'docs'` | Lowercase plural display name |

### Usage in Templates

```twig
{# Page heading using the configured plugin name #}
<h1>{{ docsManagerHelper.fullName }}</h1>

{# Lower-case reference in prose #}
<p>Add a new {{ docsManagerHelper.lowerDisplayName }} source to get started.</p>

{# Plural form in headings #}
<h2>All {{ docsManagerHelper.pluralDisplayName }}</h2>
```

### Custom Plugin Name

If you rename the plugin via `config/docs-manager.php`, all helper properties reflect the new name automatically:

```php
// config/docs-manager.php
return [
    'pluginName' => 'Plugin Docs',
];
```

After this change, `docsManagerHelper.fullName` returns `'Plugin Docs'` and `docsManagerHelper.displayName` returns `'Plugin Docs'`.

> [!TIP]
> `docsManagerHelper` is available in both CP and frontend templates. For querying documentation data (sources, pages, navigation), use `craft.docsManager` instead — see [Template Variables](template-variables.md).
