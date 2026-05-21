# Quickstart

Get Docs Manager running in under 5 minutes. By the end of this guide you'll have a plugin source synced and its documentation serving from your Craft frontend.

## 1. Install the Plugin

See [Installation](installation.md) for full details including DDEV and Composer options.

## 2. Set `localPluginBasePath` in Your Config

Create `config/docs-manager.php` and point it at the folder that contains your plugin directories:

```php
// config/docs-manager.php
return [
    'localPluginBasePath' => '@root/plugins',
];
```

The path is a Craft alias. `@root/plugins` resolves to the `plugins/` folder at your project root — the default for local development.

## 3. Add a Source

1. Go to **Docs Manager > Sources** in the CP
2. Click **New Source**
3. Select **Plugin** as the kind and **Local** as the source type
4. Use the **Quick Add** dropdown — it lists any plugin under `localPluginBasePath` that has both `docs/index.json` and `docs/plugin.json`
5. Select your plugin — name, handle, and description auto-populate
6. Click **Save** — a sync runs automatically on save

> [!NOTE]
> If the Quick Add dropdown is empty, verify that `docs/index.json` and `docs/plugin.json` exist in the plugin's `docs/` folder — both are required for Quick Add discovery. If the source appears but sync produces no pages, verify that `docs/.sidebar.json` is present — it is required for sync.

## 4. Verify the Sync

Go to **Docs Manager > Sources** and confirm the source shows a last-synced timestamp. Click the source to see synced page counts.

## What's Next

- [Configuration](configuration.md) — set GitHub token, enable auto-sync, configure code highlighting
- [Feature Tour](../feature-tour/overview.md) — explore sources, syncing, frontend templates, and changelogs
