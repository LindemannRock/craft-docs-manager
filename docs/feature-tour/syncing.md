# Syncing

Syncing reads markdown files from a source, parses them into HTML, and stores the result in the database. Visitors never wait on file reads or GitHub API calls — they always read from the database.

## What Gets Synced

- Parsed doc pages (HTML + headings)
- Sidebar navigation from `.sidebar.json`
- Version info (GitHub API → Packagist → git tags → `composer.json`)
- Icon SVG from `src/icon.svg`
- Raw CHANGELOG.md content

## Versioned Docs @since(5.2.0)

Sync runs once per configured docs version on the source. A source with `main`, `craft-5`, and `craft-4` versions will read `/docs/` from each configured ref and store the pages separately.

```text
main     -> /plugins/{handle}/docs/...
craft-5  -> /plugins/{handle}/docs/v5/...
craft-4  -> /plugins/{handle}/docs/v4/...
craft-6-beta -> /plugins/{handle}/docs/v6-beta/...
```

Each branch or ref still contains a normal `/docs/` folder. You do not create `docs/v5/` folders inside the plugin repository. Docs Manager stores the synced pages with the source/version tuple in the database.

For local sources, pinned versions are read from the configured Git ref with `git show`, so a local test repository can expose `craft-5` docs without checking out that branch. For GitHub API sources, pinned versions are fetched with the configured ref. Versioned images are served from the same ref, so `/docs/v5/images/example.webp` resolves to `docs/images/example.webp` on the `craft-5` ref.

## `.sidebar.json` is Required

Sync reads `.sidebar.json` to determine which pages to process. Only pages listed in `.sidebar.json` are synced to the database. **If `.sidebar.json` is missing, sync will fail or produce no pages.**

## Manual Sync (CP)

- **Source edit page** — Click **Resync Source Data**
- **Sources list** — Click the sync icon next to a source
- **Sources list** — Click **Sync All** to sync every enabled source

## Manual Sync (CLI)

Sync a specific source. You can pass either a DB handle or a folder name — the command resolves both:

```bash title="PHP"
php craft docs-manager/sync/plugin search-manager
```

```bash title="DDEV"
ddev craft docs-manager/sync/plugin search-manager
ddev craft docs-manager/sync/plugin base              # folder name works too
```

Sync all enabled sources:

```bash title="PHP"
php craft docs-manager/sync
```

```bash title="DDEV"
ddev craft docs-manager/sync
```

## Automatic Sync

Enable `autoSync` in your config file. A queue job syncs all enabled sources on the configured schedule.

```php
// config/docs-manager.php
return [
    'production' => [
        'autoSync' => true,
        'syncSchedule' => 'daily', // hourly, daily, weekly, monthly
    ],
];
```

Changing `syncSchedule` replaces the pending automatic sync queue row so the next run follows the newly selected schedule.

Craft stores queue job descriptions when rows are queued, so date/time format changes apply to newly queued rows. Existing delayed rows keep their old label until they run or are requeued. Queue labels stay compact: numeric months render numerically, while short and long month settings both render as short month names.

## Orphan Page Cleanup

When a page is removed from `.sidebar.json`, it becomes an orphan. On the next sync, Docs Manager automatically deletes orphaned pages from the database for that same source/version — any `SourceDoc` element whose slug no longer appears in that version's `.sidebar.json` is removed. This keeps the database in sync with the doc files on disk.

> [!WARNING]
> Removing a page from `.sidebar.json` and running sync permanently deletes that page from the database. If the removal was accidental, re-add the entry to `.sidebar.json` before syncing.

## Sidebar Navigation Ordering

The `.sidebar.json` controls page order within each section. Order pages by:

1. **Dependency order** — Pages that explain prerequisites come first
2. **Frequency of use** — Most-used features before niche ones
3. **Never purely alphabetical** — Alphabetical order only as a tiebreaker
