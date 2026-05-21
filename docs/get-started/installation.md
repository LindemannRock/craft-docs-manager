# Installation & Setup

> [!NOTE]
> Docs Manager is in active development and not yet available on the Craft Plugin Store. Install via Composer for now.

## Composer

Add the package to your project using Composer and the command line.

1. Open your terminal and go to your Craft project:

```bash
cd /path/to/project
```

2. Then tell Composer to require the plugin, and Craft to install it:

```bash title="Composer"
composer require lindemannrock/craft-docs-manager && php craft plugin/install docs-manager
```

```bash title="DDEV"
ddev composer require lindemannrock/craft-docs-manager && ddev craft plugin/install docs-manager
```

> [!NOTE]
> Logging Library and Code Highlighter are included as Composer dependencies and downloaded automatically. Activate them in Craft to enable their features.

3. **Optional** — Enable [Logging Library](https://github.com/LindemannRock/craft-logging-library) for log viewing:

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

4. **Optional** — Enable [Code Highlighter](https://github.com/LindemannRock/craft-code-highlighter) for syntax highlighting:

```bash title="PHP"
php craft plugin/install code-highlighter
```

```bash title="DDEV"
ddev craft plugin/install code-highlighter
```

Or via the Control Panel: **Settings → Plugins → Code Highlighter → Install**

## Copy Config File (Optional)

For advanced configuration, copy the config file to your project:

```bash
cp vendor/lindemannrock/craft-docs-manager/src/config.php config/docs-manager.php
```

This gives you full control over paths, sync schedules, and all plugin settings. See [Configuration](configuration.md) for details.

## Quick Start

See [Quickstart](quickstart.md) for the fastest path from install to first result.
