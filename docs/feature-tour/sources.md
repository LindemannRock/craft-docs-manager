# Sources

A source represents a plugin or theme whose documentation is managed by Docs Manager. Each source has its own docs, sidebar navigation, changelog, and version info.

## Source Kinds

Each source has a `kind` field, displayed as a color-coded badge in the CP:

| Kind | Color | Description |
|------|-------|-------------|
| `plugin` | Blue | Craft CMS plugin |
| `theme` | Purple | Craft CMS theme (for specific verticals like medical, commerce, etc.) |

## Source Types

Each source can sync from one of two source types:

| Type | Use Case |
|------|----------|
| `local` | Development — reads from local filesystem |
| `github-api` | Production — reads from GitHub via API |

## Adding a Source

1. Go to **Docs Manager > Sources** in the CP
2. Click **New Source**
3. Select kind (Plugin or Theme)
4. Select source type (Local or GitHub API)
5. Use the **Quick Add** dropdown — for local sources it lists any plugin that has `docs/index.json` AND either `docs/plugin.json` (plugins) or `docs/theme.json` (themes). For GitHub sources it lists repositories with `docs/index.json`.
6. Select a source — name, handle, and description auto-populate
7. Click **Save** — sync runs automatically

> [!NOTE]
> For **local** sources you can skip these steps entirely: run `php craft docs-manager/sync/plugin <handle>` (or `ddev craft …`) and the source is created automatically on first sync, as long as the handle matches a plugin/module folder containing a `composer.json` under your **Local Plugin Base Path**. GitHub sources must be added here in the CP. @since(5.2.0)

## Docs Versions @since(5.2.0)

Each source is added once. Versioned documentation is configured as child rows on the source edit page, not as duplicate sources.

The default version is always `main`, uses the unversioned docs URL, and is labeled Latest:

```text
/plugins/{handle}/docs/get-started/installation
```

Pinned versions sync from non-main Git refs and use a constrained `vN` URL segment:

```text
/plugins/{handle}/docs/v5/get-started/installation
/plugins/{handle}/docs/v4/get-started/installation
```

Use pinned versions for frozen maintenance branches such as `craft-5` or `craft-4`. The version row stores the display label (`5.x`), URL slug (`v5`), Git ref (`craft-5`), status (`stable`, `beta`, `alpha`, or `retired`), sync time, and last sync error.

> [!NOTE]
> `main` is always the default docs ref. Alpha or beta docs should use a non-main ref with a versioned URL such as `/docs/v7/...`.

## Documentation Structure

Each source's docs live in a `/docs/` folder:

```
plugins/{handle}/docs/
├── .sidebar.json           # Navigation structure (sections + page order)
├── index.json              # Source metadata (required for discovery)
├── plugin.json             # Required for plugin sources
├── theme.json              # Required for theme sources (instead of plugin.json)
├── get-started/
│   ├── requirements.md
│   ├── installation.md
│   └── configuration.md
├── feature-tour/
│   ├── overview.md
│   └── {feature}.md
├── developers/
│   ├── permissions.md
│   ├── console-commands.md
│   └── template-variables.md
└── resources/
    └── troubleshooting.md
```

## Required Files

### `docs/index.json`

Source metadata for discovery. Without this, the source won't appear in the Quick Add dropdown.

```json
{
    "plugin": {
        "name": "Plugin Name",
        "handle": "plugin-handle",
        "description": "Short description."
    }
}
```

### `docs/plugin.json` or `docs/theme.json`

Determines the source kind. A source must have one of these files to be discovered.

### `docs/.sidebar.json`

Navigation structure. Defines sections and page order. **Required for sync** — pages not listed here are not synced to the database.

```json
[
    {
        "title": "Get Started",
        "children": [
            "get-started/requirements",
            "get-started/installation",
            "get-started/configuration"
        ]
    },
    {
        "title": "Feature Tour",
        "children": [
            "feature-tour/overview"
        ]
    }
]
```

Children paths are relative to `/docs/` without `.md` extension.
