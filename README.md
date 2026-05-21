# Docs Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-docs-manager.svg)](https://packagist.org/packages/lindemannrock/craft-docs-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0%2B-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-docs-manager.svg)](LICENSE)

Sync and serve documentation for plugins and themes from markdown files on your Craft CMS website. Supports local filesystem and GitHub API sources with automatic scheduled syncing.

## ⚠️ Pre-Release

This plugin is in active development and not yet available on the Craft Plugin Store. Features and APIs may change before the initial public release.

## Features

- **Dual Source Support** — Sync from local filesystem or GitHub API per-source
- **Plugin & Theme Sources** — Each source has a kind (plugin or theme) for filtering
- **Automatic Sync** — Scheduled sync via Craft queue (hourly, daily, weekly, monthly)
- **Custom Pages** — CP-managed pages (FAQ, features, pricing, support) attached to a source alongside synced docs
- **Code Extraction** — Auto-generate docs skeleton from PHP source (Settings, permissions, commands, events, Twig variables)
- **CommonMark Parser** — Full markdown parsing with syntax highlighting, anchor generation, and `.md` link stripping
- **Sidebar Navigation** — Driven by `.sidebar.json` with section grouping
- **On This Page** — Auto-generated heading anchors for in-page navigation
- **Changelog Parsing** — Syncs and parses CHANGELOG.md with CommonMark rendering
- **Version Detection** — Reads source versions from `composer.json` / CHANGELOG / Packagist / git tags
- **Quick Add** — Auto-discover sources with `docs/index.json` via dropdown
- **Prev/Next Navigation** — Automatic previous and next page links
- **Frontend Templates** — Installable starter templates for source listing, docs, and changelog pages

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- [Logging Library](https://github.com/LindemannRock/craft-logging-library) 5.0+ — installed automatically; enable in CP to activate log viewing
- [Code Highlighter](https://github.com/LindemannRock/craft-code-highlighter) 5.0+ — installed automatically; enable in CP to activate syntax highlighting

## Installation

### Via Composer

```bash
composer require lindemannrock/craft-docs-manager
```

```bash
php craft plugin/install docs-manager
```

### Using DDEV

```bash
ddev composer require lindemannrock/craft-docs-manager
```

```bash
ddev craft plugin/install docs-manager
```

## Documentation

Full documentation is available in the [docs](docs/) folder.

## Support

- **Documentation**: [https://github.com/LindemannRock/craft-docs-manager](https://github.com/LindemannRock/craft-docs-manager)
- **Issues**: [https://github.com/LindemannRock/craft-docs-manager/issues](https://github.com/LindemannRock/craft-docs-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/). See [LICENSE.md](LICENSE.md) for details.

---

Developed by [LindemannRock](https://lindemannrock.com)
