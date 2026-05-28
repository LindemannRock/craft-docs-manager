# Configuration

Configure Docs Manager by creating `config/docs-manager.php`. Any setting in this file overrides the value stored in the database (set via the CP). Use multi-environment keys (`*`, `dev`, `production`) to vary settings per environment.

## General

These settings control the plugin's display name and logging behavior.

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `pluginName` | `string` | `'Docs Manager'` | Plugin display name in the CP. Required. |
| `logLevel` | `string` | `'error'` | Log verbosity: `error`, `warning`, `info`, `debug` |

### Logging

The `debug` log level requires Craft's `devMode` to be enabled. If `devMode` is off:

- When set via the CP: the value is automatically demoted to `info` and saved back to the database.
- When set via `config/docs-manager.php`: the value is demoted to `info` for the current request and a warning is logged. The config file is not modified — fix it manually.

## Source Defaults

These settings define the defaults applied when adding a new source. Existing sources are not affected when these change.

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `defaultSourceType` | `string` | `'local'` | Default source type for new sources: `local` or `github-api` |
| `localPluginBasePath` | `string\|null` | `'@root/plugins'` | Base path for local plugin sources. Accepts Craft aliases and environment variables. The resolved directory must exist. |
| `githubToken` | `string\|null` | `null` | GitHub personal access token for GitHub API sources |

`localPluginBasePath` supports Craft aliases such as `@root/plugins`, absolute paths to existing directories, and environment variables such as `$DOCS_PLUGIN_BASE_PATH`. In config files, `localPluginBasePath` and `githubToken` can also use `craft\helpers\App::env()`.

## Sync

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `autoSync` | `bool` | `false` | Enable automatic scheduled sync via the Craft queue |
| `syncSchedule` | `string` | `'daily'` | Sync frequency when `autoSync` is enabled: `hourly`, `daily`, `weekly`, `monthly` |

## Parser

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enableSyntaxHighlighting` | `bool` | `true` | Apply syntax highlighting to code blocks (requires Code Highlighter plugin) |
| `enableAnchorGeneration` | `bool` | `true` | Generate `id` attributes on headings for anchor links |

## Code Highlighting

These settings are passed to the Code Highlighter plugin when `enableSyntaxHighlighting` is `true`. They have no effect when Code Highlighter is not installed.

| Setting | Type | Default | Validation |
|---------|------|---------|------------|
| `codeTheme` | `string` | `'tomorrow'` | Max 50 characters |
| `codeFontSize` | `int` | `14` | 8–32 (pixels) |
| `codeFontFamily` | `string\|null` | `null` | Max 255 characters |
| `codeEnableCopyButton` | `bool` | `true` | Show copy button on code blocks |
| `codeShowLineNumbers` | `bool` | `true` | Show line numbers on code blocks |

## Site Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enabledSites` | `int[]` | `[]` | Site IDs where Docs Manager is active. Empty array = all sites. |

When `enabledSites` is empty, Docs Manager serves documentation on every Craft site. Specify site IDs to restrict it to particular sites in a multisite setup.

## Display

| Setting | Type | Default | Validation |
|---------|------|---------|------------|
| `itemsPerPage` | `int` | `50` | 10–500 |

## Example Configuration

```php
<?php
// config/docs-manager.php

use craft\helpers\App;

return [
    '*' => [
        'pluginName' => 'Docs Manager',
        'logLevel' => 'error',

        // Source defaults
        'defaultSourceType' => 'local',
        'localPluginBasePath' => '@root/plugins',
        'githubToken' => App::env('GITHUB_TOKEN'),

        // Sync
        'autoSync' => false,
        'syncSchedule' => 'daily',

        // Parser
        'enableSyntaxHighlighting' => true,
        'enableAnchorGeneration' => true,

        // Code highlighting
        'codeTheme' => 'tomorrow',
        'codeFontSize' => 14,
        'codeFontFamily' => null,
        'codeEnableCopyButton' => true,
        'codeShowLineNumbers' => true,
    ],
    'dev' => [
        'logLevel' => 'debug',
        'defaultSourceType' => 'local',
    ],
    'production' => [
        'logLevel' => 'error',
        'defaultSourceType' => 'github-api',
        'autoSync' => true,
        'syncSchedule' => 'daily',
    ],
];
```

## GitHub Token Setup

For GitHub API sources, create a [Personal Access Token](https://github.com/settings/tokens) with `repo` scope and add it to your `.env` file:

```bash
# .env
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxx
```

Then reference it in your config:

```php
'githubToken' => App::env('GITHUB_TOKEN'),
```
