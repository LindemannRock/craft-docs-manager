# Events @since(5.0.0)

Docs Manager registers standard Craft events that modules and plugins can listen to for extending or reacting to plugin behaviour.

## Element Types @since(5.0.0)

Docs Manager registers two custom element types with Craft's element system. You can listen to standard Craft element events on either of them.

### SourceDoc

Represents a documentation page synced from a markdown source file.

**Class:** `lindemannrock\docsmanager\elements\SourceDoc`

```php
use craft\base\Element;
use craft\events\ModelEvent;
use lindemannrock\docsmanager\elements\SourceDoc;
use yii\base\Event;

// Before a SourceDoc is saved
Event::on(
    SourceDoc::class,
    Element::EVENT_BEFORE_SAVE,
    function(ModelEvent $event) {
        /** @var SourceDoc $sourceDoc */
        $sourceDoc = $event->sender;
        // $sourceDoc->slug, ->sourceId, ->htmlContent, etc.
    }
);

// After a SourceDoc is saved
Event::on(
    SourceDoc::class,
    Element::EVENT_AFTER_SAVE,
    function(ModelEvent $event) {
        /** @var SourceDoc $sourceDoc */
        $sourceDoc = $event->sender;
    }
);

// Before a SourceDoc is deleted
Event::on(
    SourceDoc::class,
    Element::EVENT_BEFORE_DELETE,
    function(ModelEvent $event) {
        /** @var SourceDoc $sourceDoc */
        $sourceDoc = $event->sender;
    }
);
```

### PluginPage

Represents a CP-editable custom page (FAQ, Features, Support, etc.) attached to a source.

**Class:** `lindemannrock\docsmanager\elements\PluginPage`

```php
use craft\base\Element;
use craft\events\ModelEvent;
use lindemannrock\docsmanager\elements\PluginPage;
use yii\base\Event;

// After a PluginPage is saved
Event::on(
    PluginPage::class,
    Element::EVENT_AFTER_SAVE,
    function(ModelEvent $event) {
        /** @var PluginPage $page */
        $page = $event->sender;
        // $page->pageType, ->sourceId, ->slug, etc.
    }
);
```

## System Events @since(5.0.0)

The following standard Craft system events are registered by Docs Manager during `init()`. These are standard Craft extension points — you can add additional listeners in your own module.

### Element Type Registration

Docs Manager registers `SourceDoc` and `PluginPage` with Craft's element system:

```php
use craft\events\RegisterComponentTypesEvent;
use craft\services\Elements;
use yii\base\Event;

Event::on(
    Elements::class,
    Elements::EVENT_REGISTER_ELEMENT_TYPES,
    function(RegisterComponentTypesEvent $event) {
        // SourceDoc and PluginPage are already registered by Docs Manager.
        // You can register additional element types here.
    }
);
```

### URL Rules

Docs Manager registers its CP routes via `UrlManager::EVENT_REGISTER_CP_URL_RULES`. To inspect the registered rules, listen after Docs Manager has registered them:

```php
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

Event::on(
    UrlManager::class,
    UrlManager::EVENT_REGISTER_CP_URL_RULES,
    function(RegisterUrlRulesEvent $event) {
        // Docs Manager rules are already in $event->rules.
        // Add your own here if needed.
    }
);
```

### Permissions

Docs Manager registers its permissions via `UserPermissions::EVENT_REGISTER_PERMISSIONS`. See [Permissions](permissions.md) for the full permission list.

### Cache Options

Docs Manager adds a `docs-manager-cache` option to Craft's **Utilities → Clear Caches** tool via `ClearCaches::EVENT_REGISTER_CACHE_OPTIONS`.

### Template Roots

Docs Manager registers a CP template root (`docs-manager`) via `View::EVENT_REGISTER_CP_TEMPLATE_ROOTS`. This maps `docs-manager/` template paths to the plugin's `src/templates/` directory.

### Variable Init

Docs Manager attaches `craft.docsManager` to Craft's template variable object via `CraftVariable::EVENT_INIT`. See [Template Variables](template-variables.md) for the full variable API.
