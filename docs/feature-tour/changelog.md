# Changelog Parsing

Docs Manager automatically syncs each source's `CHANGELOG.md` during sync — for both local and GitHub sources. The raw changelog content is stored in the database and parsed on-the-fly using the [Keep a Changelog](https://keepachangelog.com/) format.

## What Gets Parsed

- Release versions and dates (`## [1.2.3] - 2025-10-27`)
- Section types: Added, Improved/Changed, Fixed, Removed, Deprecated, Security
- Per-release stats (count of added, improved, fixed items)
- Markdown formatting via CommonMark (bold, italic, code, links)
- Commit hash shortening (`[abc123f...]` → `[abc123f]`)

## Template Usage

```twig
{% set changelog = craft.docsManager.getChangelog('search-manager') %}

{# Latest release #}
{{ changelog.latest.version }}      {# "1.5.0" #}
{{ changelog.latest.date }}         {# "2026-01-15" #}
{{ changelog.latest.stats.added }}  {# 3 #}

{# All releases #}
{% for release in changelog.releases %}
    <h2>{{ release.version }}</h2>
    {% for item in release.added %}
        <li>{{ item|raw }}</li>
    {% endfor %}
{% endfor %}
```

Items are returned as HTML (parsed via CommonMark). Use `|raw` in templates and wrap in a `prose` container for link/code styling.
