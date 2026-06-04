# Console Commands

Docs Manager provides console commands for generating docs skeletons, migrating READMEs, syncing sources, and installing frontend templates.

## Plugin Identifier @since(5.0.0)

All commands that accept a `<plugin>` argument resolve the input flexibly:

1. **DB handle** — looks up the source record by handle (e.g., `lindemannrock-base`)
2. **Folder name** — finds the folder under `localPluginBasePath` and reads `composer.json` for the canonical handle (e.g., `base`)

```bash title="PHP"
php craft docs-manager/sync/plugin base               # folder name → resolves to lindemannrock-base
php craft docs-manager/sync/plugin lindemannrock-base # DB handle → works directly
```

```bash title="DDEV"
ddev craft docs-manager/sync/plugin base              # folder name → resolves to lindemannrock-base
ddev craft docs-manager/sync/plugin lindemannrock-base # DB handle → works directly
```

---

## Generate Docs Skeleton @since(5.0.0)

Scans a plugin's PHP source and generates the auto-generated doc files: `configuration.md`, `permissions.md`, `console-commands.md`, `events.md`, `template-variables.md`, `twig-globals.md`, `shared-features.md`, `.sidebar.json`, and `plugin.json`.

The `--plugin` option is optional. If omitted and the command is run from inside a plugin directory (one with a `composer.json`), the plugin is auto-detected from the current directory.

```bash title="PHP"
php craft docs-manager/docs/create --plugin=<plugin>
```

```bash title="DDEV"
ddev craft docs-manager/docs/create --plugin=<plugin>
```

| Option | Description |
|--------|-------------|
| `--plugin=<handle>` | Plugin handle to generate docs for |
| `--force` | Overwrite existing files |
| `--dryRun` | Preview what would be generated without writing any files |
| `--verbose` | Show detailed extraction counts (settings, variables, commands, etc.) |

Preview without writing:

```bash title="PHP"
php craft docs-manager/docs/create --plugin=search-manager --dryRun --verbose
```

```bash title="DDEV"
ddev craft docs-manager/docs/create --plugin=search-manager --dryRun --verbose
```

---

## Migrate README to Docs @since(5.0.0)

Extracts sections from a plugin's `README.md` and writes them into structured doc files under `docs/`.

The `--plugin` option is optional. If omitted and the command is run from inside a plugin directory, the plugin is auto-detected from the current directory.

```bash title="PHP"
php craft docs-manager/docs/migrate --plugin=<plugin>
```

```bash title="DDEV"
ddev craft docs-manager/docs/migrate --plugin=<plugin>
```

| Option | Description |
|--------|-------------|
| `--plugin=<handle>` | Plugin handle to migrate docs for |
| `--dryRun` | Preview what would be generated without writing any files |
| `--verbose` | Show section-by-section extraction details |

> [!NOTE]
> The migrate command never overwrites existing files — if a target doc file already exists, that section is skipped. Re-run after manually deleting files to re-create them.

---

## Sync All Sources @since(5.0.0)

Syncs all enabled sources to the database.

```bash title="PHP"
php craft docs-manager/sync
```

```bash title="DDEV"
ddev craft docs-manager/sync
```

| Option | Description |
|--------|-------------|
| `--plugin=<handle>` | Sync only the specified plugin instead of all sources |

Example — sync a single source via the index action:

```bash title="PHP"
php craft docs-manager/sync --plugin=search-manager
```

```bash title="DDEV"
ddev craft docs-manager/sync --plugin=search-manager
```

---

## Sync Single Source @since(5.0.0)

Syncs a specific source's docs to the database. Use this after editing markdown files locally.

```bash title="PHP"
php craft docs-manager/sync/plugin <plugin>
```

```bash title="DDEV"
ddev craft docs-manager/sync/plugin <plugin>
```

Example:

```bash title="PHP"
php craft docs-manager/sync/plugin search-manager
```

```bash title="DDEV"
ddev craft docs-manager/sync/plugin search-manager
```

---

## Check Version @since(5.0.0)

Checks the detected version for a source. Reports which detection method succeeded (GitHub API, Packagist, git tags, or `composer.json`).

```bash title="PHP"
php craft docs-manager/sync/version <plugin>
```

```bash title="DDEV"
ddev craft docs-manager/sync/version <plugin>
```

---

## Test Parser @since(5.0.0)

Tests the markdown parser on a single file and prints the resulting HTML. Useful for debugging parsing issues before a full sync.

```bash title="PHP"
php craft docs-manager/sync/test-parser <filePath>
```

```bash title="DDEV"
ddev craft docs-manager/sync/test-parser <filePath>
```

Example:

```bash title="PHP"
php craft docs-manager/sync/test-parser plugins/search-manager/docs/feature-tour/overview.md
```

```bash title="DDEV"
ddev craft docs-manager/sync/test-parser plugins/search-manager/docs/feature-tour/overview.md
```

---

## Install Templates @since(5.0.0)

Copies starter frontend templates to `templates/plugins/`. Installs a source listing page, source detail page, documentation page (with sidebar and "On This Page" navigation), and a changelog page.

```bash title="PHP"
php craft docs-manager/templates/install
```

```bash title="DDEV"
ddev craft docs-manager/templates/install
```
