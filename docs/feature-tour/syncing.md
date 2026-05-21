# Syncing

Syncing reads markdown files from a source, parses them into HTML, and stores the result in the database. Visitors never wait on file reads or GitHub API calls — they always read from the database.

## What Gets Synced

- Parsed doc pages (HTML + headings)
- Sidebar navigation from `.sidebar.json`
- Version info (GitHub API → Packagist → git tags → `composer.json`)
- Icon SVG from `src/icon.svg`
- Raw CHANGELOG.md content

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

## Orphan Page Cleanup

When a page is removed from `.sidebar.json`, it becomes an orphan. On the next sync, Docs Manager automatically deletes orphaned pages from the database — any `SourceDoc` element whose slug no longer appears in `.sidebar.json` is removed. This keeps the database in sync with the doc files on disk.

> [!WARNING]
> Removing a page from `.sidebar.json` and running sync permanently deletes that page from the database. If the removal was accidental, re-add the entry to `.sidebar.json` before syncing.

## Sidebar Navigation Ordering

The `.sidebar.json` controls page order within each section. Order pages by:

1. **Dependency order** — Pages that explain prerequisites come first
2. **Frequency of use** — Most-used features before niche ones
3. **Never purely alphabetical** — Alphabetical order only as a tiebreaker
