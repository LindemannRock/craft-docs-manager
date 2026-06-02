<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\db\PluginPageQuery;
use lindemannrock\docsmanager\records\PluginPageRecord;
use lindemannrock\docsmanager\records\SourceRecord;

/**
 * PluginPage element
 *
 * Represents a CP-editable custom page (FAQ, Features, Support, etc.) for a source.
 * Uses Craft's field layout system for content — no custom content table needed.
 *
 * @author    LindemannRock
 * @package   DocsManager
 * @since     5.0.0
 */
class PluginPage extends Element
{
    // Properties
    // =========================================================================

    /**
     * @var int|null Source record ID (FK to docsmanager_sources)
     */
    public ?int $sourceId = null;

    /**
     * @var string|null Page type (e.g., 'features', 'faq', 'support')
     */
    public ?string $pageType = null;

    /**
     * @var string|null URL slug
     */
    public ?string $slug = null;

    /**
     * @var int Sort position
     */
    public int $order = 0;

    /**
     * @var string|null Cached source handle
     */
    private ?string $_sourceHandle = null;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('docs-manager', 'Custom Page');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('docs-manager', 'custom page');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('docs-manager', 'Custom Pages');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('docs-manager', 'custom pages');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'pluginPage';
    }

    /**
     * @inheritdoc
     */
    public static function trackChanges(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ENABLED => Craft::t('docs-manager', 'Enabled'),
            self::STATUS_DISABLED => Craft::t('docs-manager', 'Disabled'),
        ];
    }

    /**
     * @inheritdoc
     */
    public static function hasDrafts(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function hasRevisions(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     * @return PluginPageQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new PluginPageQuery(static::class);
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getSupportedSites(): array
    {
        $settings = DocsManager::getInstance()->getSettings();
        $enabledSiteIds = $settings->getEnabledSiteIds();

        return array_map(fn($siteId) => ['siteId' => $siteId, 'enabledByDefault' => true], $enabledSiteIds);
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?FieldLayout
    {
        $fieldLayouts = Craft::$app->getProjectConfig()->get('docs-manager.pluginPageFieldLayouts') ?? [];

        if (!empty($fieldLayouts)) {
            $fieldLayoutUid = array_key_first($fieldLayouts);
            $fieldLayout = Craft::$app->getFields()->getLayoutByUid($fieldLayoutUid);
            if ($fieldLayout) {
                return $fieldLayout;
            }
        }

        return Craft::$app->fields->getLayoutByType(self::class);
    }

    /**
     * Get the handle for this page's parent source
     */
    public function getSourceHandle(): ?string
    {
        if (!$this->sourceId) {
            return null;
        }

        if (!isset($this->_sourceHandle)) {
            $record = SourceRecord::findOne($this->sourceId);
            $this->_sourceHandle = $record?->handle;
        }

        return $this->_sourceHandle;
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        return $user->can('docsManager:managePages');
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        return $user->can('docsManager:editPages');
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        return $user->can('docsManager:deletePages');
    }

    /**
     * @inheritdoc
     */
    public function canCreateDrafts(User $user): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function cpEditUrl(): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteId);
        $siteHandle = $site?->handle ?? Craft::$app->getSites()->getCurrentSite()->handle;

        return sprintf('docs-manager/pages/%s?site=%s', $this->getCanonicalId(), $siteHandle);
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        if ($this->sourceId) {
            return UrlHelper::cpUrl('docs-manager/pages', ['sourceId' => $this->sourceId]);
        }

        return UrlHelper::cpUrl('docs-manager/pages');
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl(): ?string
    {
        return $this->cpEditUrl();
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title', 'sourceId', 'pageType', 'slug'], 'required'];
        $rules[] = [['title', 'pageType', 'slug'], 'filter', 'filter' => 'trim'];
        $rules[] = [['sourceId', 'order'], 'integer'];
        $rules[] = [['order'], 'integer', 'min' => 0];
        $rules[] = [['pageType'], 'string', 'max' => 50];
        $rules[] = [['slug'], 'string', 'max' => 255];
        $rules[] = [['pageType'], 'in', 'range' => ['features', 'faq', 'support', 'pricing', 'custom']];
        $rules[] = [['slug'], 'match', 'pattern' => '/^[a-z0-9][a-z0-9\-\/]*$/', 'message' => Craft::t('docs-manager', '{attribute} must start with a letter or number and may only contain lowercase letters, numbers, hyphens, and forward slashes.')];
        $rules[] = [['sourceId'], 'validateSourceExists'];
        $rules[] = [['pageType'], 'validateUniquePageType'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool
    {
        if ($this->slug !== null) {
            $this->slug = SlugHandleHelper::normalizePathSlug($this->slug, '');
        }

        return parent::beforeValidate();
    }

    public function validateSourceExists(string $attribute): void
    {
        $sourceId = (int) $this->$attribute;
        if ($sourceId <= 0) {
            return;
        }

        if (SourceRecord::findOne($sourceId) !== null) {
            return;
        }

        $this->addError($attribute, Craft::t('docs-manager', 'Selected source does not exist.'));
    }

    public function validateUniquePageType(string $attribute): void
    {
        $sourceId = (int) $this->sourceId;
        $pageType = trim((string) $this->$attribute);
        if ($sourceId <= 0 || $pageType === '') {
            return;
        }

        $query = self::find()
            ->status(null)
            ->sourceId($sourceId)
            ->pageType($pageType);

        if ($this->id !== null) {
            $query->andWhere(['not', ['elements.id' => $this->id]]);
        }

        if (!$query->exists()) {
            return;
        }

        $this->addError($attribute, Craft::t('docs-manager', 'A page with this page type already exists for the selected source.'));
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->getIsRevision() && !$this->resaving) {
            if (!$isNew) {
                $record = PluginPageRecord::findOne($this->id);
                if (!$record) {
                    throw new \Exception('Invalid custom page ID: ' . $this->id);
                }
            } else {
                $record = new PluginPageRecord();
                $record->id = $this->id;
            }

            $record->sourceId = $this->sourceId;
            $record->pageType = $this->pageType;
            $record->slug = $this->slug;
            $record->order = $this->order;

            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['slug', 'pageType'];
    }
}
