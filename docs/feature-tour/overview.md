# Overview

Docs Manager syncs and serves documentation for plugins and themes from markdown files on your Craft CMS website. It supports local filesystem and GitHub API sources with automatic scheduled syncing.

## How It Works

```
Source (Local/GitHub)  ──  Sync  ──>  Database  ──>  Frontend Templates
                            ↑            ↑                ↑
                      manual or      parsed HTML      every visitor
                      scheduled      + headings       reads from DB
                                     + changelog
```

**Sync stores**: parsed doc pages (HTML + headings), sidebar navigation, version info, icon SVG, and raw CHANGELOG.md content.

**In development** (local source): Edit markdown files, run sync manually.

**In production** (GitHub source): Push docs to GitHub, auto-sync picks them up on schedule.

## Key Features

- **Dual Source Support** — Sync from local filesystem or GitHub API per-source
- **Plugin & Theme Sources** — Each source has a `kind` (plugin or theme) for filtering
- **Versioned Docs** — Keep default docs at `/docs/...` and sync pinned Git refs to `/docs/v5/...`, `/docs/v4/...`, and other versioned URLs
- **Automatic Sync** — Scheduled sync via Craft queue (hourly, daily, weekly, monthly)
- **Code Extraction** — Auto-generate docs skeleton from PHP source (settings, permissions, commands, events, Twig variables)
- **CommonMark Parser** — Full markdown parsing with syntax highlighting, anchor generation, and `.md` link stripping
- **Code Highlighter Integration** — Uses Code Highlighter plugin for syntax highlighting with graceful fallback
- **Sub-Path URLs** — Deep path routing (`/plugins/handle/docs/get-started/installation`)
- **Sidebar Navigation** — Driven by `.sidebar.json` with section grouping
- **On This Page** — Auto-generated heading anchors for in-page navigation
- **Changelog Parsing** — Syncs and parses CHANGELOG.md with CommonMark rendering
- **Version Detection** — Reads source versions via GitHub API → Packagist → local git tags → `composer.json` (in priority order)
- **Quick Add** — Auto-discover sources with `docs/index.json` via dropdown, filtered by kind
- **Custom Pages** — CP-managed pages (FAQ, features, pricing, support) attached to a source alongside synced docs
- **Prev/Next Navigation** — Automatic previous and next page links
