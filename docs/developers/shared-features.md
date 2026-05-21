# Shared Features @since(5.0.0)

Docs Manager is built on the [LindemannRock Base Plugin](https://github.com/LindemannRock/craft-plugin-base) (`lindemannrock/craft-plugin-base`). This page documents which base features are in use and what each one provides.

## SettingsPersistenceTrait

**Used in:** `Settings.php`

Stores plugin settings in a dedicated database table (`docsmanager_settings`) instead of Craft's project config. Settings survive environment resets, are not tracked in version control, and can differ per environment.

```php
// Settings saved to DB automatically when changed in CP
protected static function tableName(): string { return 'docsmanager_settings'; }
protected static function booleanFields(): array { return ['autoSync', 'enableSyntaxHighlighting', ...]; }
protected static function integerFields(): array { return ['itemsPerPage', 'codeFontSize']; }
protected static function jsonFields(): array { return ['enabledSites']; }
```

See the base plugin's `SettingsPersistenceTrait` documentation for the full API.

## SettingsConfigTrait

**Used in:** `Settings.php`

Allows any setting to be overridden via `config/docs-manager.php` without losing the DB-stored value. The config file takes precedence over the database value for the duration of the request.

This also exposes `isOverriddenByConfig(string $field): bool`, used internally to detect whether a setting (like `logLevel`) was set via the config file rather than the CP.

## SettingsDisplayNameTrait

**Used in:** `Settings.php`

Derives human-friendly label variants from the `$pluginName` property. These are used in CP headings, log entries, and cache option labels.

| Method | Returns (default `'Docs Manager'`) |
|--------|------------------------------------|
| `getFullName()` | `'Docs Manager'` |
| `getDisplayName()` | `'Docs'` |
| `getPluralDisplayName()` | `'Docs'` |
| `getLowerDisplayName()` | `'docs'` |
| `getPluralLowerDisplayName()` | `'docs'` |

## ColorHelper (Color Sets)

**Used in:** `DocsManager::init()` via `PluginHelper::bootstrap()`

Registers two plugin-specific color sets for use with the base plugin's badge and filter components:

| Color Set | Keys |
|-----------|------|
| `pageType` | `features`, `faq`, `support`, `pricing`, `custom` |
| `sourceKind` | `plugin`, `theme` |

These sets are available anywhere `ColorHelper::getColorSet('pageType')` or `ColorHelper::getColorSet('sourceKind')` is called — including CP templates that render badges.

## PluginHelper::bootstrap()

**Used in:** `DocsManager::init()`

A single call that wires up:

- The base module (`Base::register()`)
- The `docsManagerHelper` Twig global (see [Twig Globals](twig-globals.md))
- Logging Library integration (`docsManager:viewSystemLogs`, `docsManager:downloadSystemLogs`)
- The `pageType` and `sourceKind` color sets

```php
PluginHelper::bootstrap(
    $this,
    'docsManagerHelper',
    ['docsManager:viewSystemLogs'],
    ['docsManager:downloadSystemLogs'],
    [
        'colorSets' => [
            'pageType' => [...],
            'sourceKind' => [...],
        ],
    ]
);
```

## PluginHelper::isPluginEnabled()

**Used in:** `DocsManager::getCpNavItem()`

Checks whether the Logging Library plugin is installed and enabled before attempting to add the Logs nav item. This prevents errors if the logging dependency is not present.

```php
if (PluginHelper::isPluginEnabled('logging-library')) {
    $item = LoggingLibrary::addLogsNav($item, $this->handle, [...]);
}
```

## CodeHighlighterTrait

**Used in:** `Settings.php` and `DocsManagerTwigExtension`

Integrates with the `craft-code-highlighter` plugin for syntax highlighting in rendered documentation HTML. The trait provides:

- `isCodeHighlighterAvailable(): bool` — checks if the plugin is installed
- `applyCodeTheme(string $theme): void` — sets the active Prism theme
- `highlightCode(string $code, string $language, array $options): string` — highlights a code block
- `getAvailableThemes(): array` — returns all registered themes from the plugin

In `Settings.php`, `getAvailableCodeThemes()` delegates to `getAvailableThemes()` from this trait, falling back to `['default' => 'Default']` when the plugin is absent.

## LoggingTrait / LoggingLibrary

**Used in:** `DocsManager` (main plugin class), `Settings.php`, and all service classes

Provides structured logging via the Logging Library plugin. Log entries are visible in **Docs Manager > Logs** when the `logging-library` plugin is installed and the user has the `docsManager:viewSystemLogs` permission.

The log level is controlled by the `logLevel` setting (`error`, `warning`, `info`, `debug`). See [Configuration](../get-started/configuration.md#logging) for the `debug` level restriction that applies when `devMode` is disabled.
