# Troubleshooting

## Source Not Appearing in Quick Add Dropdown

- Source must have `docs/index.json` in its `/docs/` folder
- Source must have either `docs/plugin.json` (for plugins) or `docs/theme.json` (for themes)
- Source folder must not start with `_` or `.`
- For GitHub: verify token is configured and has `repo` scope

## Sync Failing

- Check source has both `docs/index.json` and `docs/.sidebar.json`
- Verify all paths in `.sidebar.json` have matching `.md` files
- Check logs at **Docs Manager > Logs** for detailed errors

## Scheduled Sync Does Not Reappear

Docs Manager schedules one recurring queue job for automatic source sync. If the queue is empty after a scheduled sync runs:

- Confirm the queue worker is running.
- Visit any CP page to let Docs Manager bootstrap the initial job.
- Check that `autoSync` is enabled.
- Check that `syncSchedule` is set to `hourly`, `daily`, `weekly`, or `monthly`.

The queued job description shows when that specific queued row is due to run.

## Pages Show 404

- Verify `config/routes.php` has both doc routes (category/page and page-only)
- Check the page slug in the database matches the URL path
- Re-sync after adding or renaming doc files

## Theme Colors Disappear in Chrome DevTools

When inspecting a docs page with Chrome DevTools open, theme colors may appear transparent or fall back to defaults. This is a **confirmed Chrome bug** — it does not affect end users or other browsers.

**Cause:** Chrome's DevTools style recalculation engine fails to resolve `var()` references inside the CSS `light-dark()` function. The docs theme system uses `light-dark(var(--palette-color), var(--palette-dark-color))` for automatic light/dark mode support.

**Affected:** Chrome (any DevTools dock position, including separate window)
**Not affected:** Safari, Firefox, or any user without DevTools open

**Workaround:** Close DevTools and reload, or use Safari/Firefox for inspecting themed styles.

**Tracking:** [Chromium Issue #484887317](https://issues.chromium.org/issues/484887317) (our report [#485535963](https://issues.chromium.org/issues/485535963) was merged as duplicate)

## Logging

Docs Manager uses the [Logging Library](https://github.com/LindemannRock/craft-logging-library) for centralized logging.

- **Location**: `storage/logs/docs-manager-YYYY-MM-DD.log`
- **Web Interface**: Docs Manager > Logs
- **Retention**: 30 days (automatic cleanup)
- Debug level requires Craft's `devMode` to be enabled
