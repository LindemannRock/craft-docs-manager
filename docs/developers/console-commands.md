# Console Commands

Docs Manager provides console commands for generating docs skeletons, migrating READMEs, syncing sources, and installing frontend templates.

## Command Help

Use the plugin help command when you need to discover available commands or confirm the correct command group.

```bash title="PHP"
php craft docs-manager/help
php craft docs-manager/help sync/plugin
```

```bash title="DDEV"
ddev craft docs-manager/help
ddev craft docs-manager/help sync/plugin
```

Craft's native help also works when you already know the exact command:

```bash title="PHP"
php craft help docs-manager/sync/plugin
```

```bash title="DDEV"
ddev craft help docs-manager/sync/plugin
```

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
| `--dry-run` | Preview what would be generated without writing any files. `--dryRun` is also accepted for backward compatibility |
| `--verbose` | Show detailed extraction counts (settings, variables, commands, etc.) |

Preview without writing:

```bash title="PHP"
php craft docs-manager/docs/create --plugin=search-manager --dry-run --verbose
```

```bash title="DDEV"
ddev craft docs-manager/docs/create --plugin=search-manager --dry-run --verbose
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
| `--dry-run` | Preview what would be generated without writing any files. `--dryRun` is also accepted for backward compatibility |
| `--verbose` | Show section-by-section extraction details |

> [!NOTE]
> The migrate command never overwrites existing files — if a target doc file already exists, that section is skipped. Re-run after manually deleting files to re-create them.

---

## Generate Hero Banner @since(5.2.0)

Generates a 1280×360 `hero.webp` banner for a plugin's README, derived entirely from the plugin's own assets: the accent and glyph colours from its `src/icon.svg`, and the name and tagline from its `composer.json`.

Every colour comes from the icon — a smooth gradient tinted by the accent colour, the title set in the icon's glyph colour, and a soft accent-tinted shadow under the icon badge — so each banner matches its plugin's brand with no manual colour input. The gradient direction adapts to the glyph colour so the text always sits on a contrasting field.

```bash title="PHP"
php craft docs-manager/hero/generate <plugin> [out] [--style=lighter]
```

```bash title="DDEV"
ddev craft docs-manager/hero/generate <plugin> [out] [--style=lighter]
```

The output path is optional and defaults to `{plugin}/docs/images/hero.webp`. Add the result to the README as the first line: `![<name>](docs/images/hero.webp)`.

| Argument / Option | Description |
|-------------------|-------------|
| `<plugin>` | Plugin handle or folder name. The icon is read from disk, so the plugin does not need to be installed or enabled. |
| `[out]` | Output path (default: `{plugin}/docs/images/hero.webp`). |
| `--name=<name>` | Override the banner title (default: composer `extra.name`). |
| `--tagline=<text>` | Override the tagline (default: composer `description`, trimmed at the first `" - "`). |
| `--style=<style>` | Gradient style: `primary`, `lighter` (default), `deeper`, or `diagonal`. |

Example — write to a scratch path without touching the committed asset:

```bash title="DDEV"
ddev craft docs-manager/hero/generate search-manager /tmp/hero-check.webp
```

Generate banners for every plugin that has a `docs/` folder and an icon. Existing banners are skipped unless `--force` is given:

```bash title="PHP"
php craft docs-manager/hero/generate-all
php craft docs-manager/hero/generate-all --force
```

```bash title="DDEV"
ddev craft docs-manager/hero/generate-all
ddev craft docs-manager/hero/generate-all --force
```

> [!NOTE]
> This is a development / authoring command — it requires the **ImageMagick CLI** (`magick`) on `PATH` and is never used at runtime. If `magick` is missing, the command exits with a clear error.

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

If the source isn't registered yet, a **local** source is created automatically the first time you sync it @since(5.2.0) — as long as `<plugin>` resolves to a real plugin/module directory (one containing a `composer.json`) under the configured **Local Plugin Base Path**. So a local plugin goes from zero to synced in a single command, with no manual onboarding. GitHub sources must still be added in the CP, since a repository URL can't be derived from a handle.

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
