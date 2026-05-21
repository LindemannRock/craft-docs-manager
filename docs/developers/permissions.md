# Permissions @since(5.0.0)

Docs Manager registers granular permissions that can be assigned to user groups via **Settings → Users → User Groups → [Group Name] → Docs Manager**.

## Permission Structure

### Sources

| Permission | Description |
|------------|-------------|
| **`docsManager:manageSources`** | Parent — access the Sources section |
| └─ `docsManager:createSources` | Add new sources |
| └─ `docsManager:editSources` | Edit, sync, enable/disable sources |
| └─ `docsManager:deleteSources` | Delete sources |

### Pages

| Permission | Description |
|------------|-------------|
| **`docsManager:managePages`** | Parent — access the Pages section |
| └─ `docsManager:createPages` | Create new custom pages |
| └─ `docsManager:editPages` | Edit, enable/disable custom pages |
| └─ `docsManager:deletePages` | Delete custom pages |

### Logs

| Permission | Description |
|------------|-------------|
| **`docsManager:viewLogs`** | Parent — access the Logs section |
| └─ `docsManager:viewSystemLogs` | View system log entries |
| &emsp;└─ `docsManager:downloadSystemLogs` | Download system log files |

### Settings

| Permission | Description |
|------------|-------------|
| `docsManager:manageSettings` | Access and save plugin settings |

## Checking Permissions

In Twig:

```twig
{% if currentUser.can('docsManager:manageSources') %}
    {# User can view sources #}
{% endif %}

{% if currentUser.can('docsManager:editSources') %}
    {# User can edit and sync sources #}
{% endif %}

{% if currentUser.can('docsManager:deletePages') %}
    {# User can delete custom pages #}
{% endif %}
```

In PHP:

```php
if (Craft::$app->getUser()->checkPermission('docsManager:manageSources')) {
    // User has permission
}

// In a controller
$this->requirePermission('docsManager:editSources');
```

## Nested Permission Pattern

Craft's nested permissions are a UI convenience — granting the parent does **not** automatically grant the children. The parent permission (`manageSources`, `managePages`) serves as the view/access gate for that CP section. Nested permissions (`create*`, `edit*`, `delete*`) control specific write operations.

To give a user read-only source access, grant only `manageSources`. To allow editing and syncing, also grant `editSources`. To allow full control, grant all four.

The same pattern applies to logs:

- **`viewLogs`** grants access to the Logs section in the CP nav
- **`viewSystemLogs`** is required to actually view log entries — grant this alongside `viewLogs`
- **`downloadSystemLogs`** enables the download button — grant alongside both parents
