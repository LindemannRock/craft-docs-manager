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
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\db\SourceDocQuery;
use lindemannrock\docsmanager\records\DocPageContentRecord;
use lindemannrock\docsmanager\records\DocPageRecord;
use lindemannrock\docsmanager\records\SourceRecord;

/**
 * SourceDoc element
 *
 * Represents a documentation page synced from markdown source files.
 * Uses the two-table pattern: non-translatable data in the main table,
 * translatable content in a separate content table.
 *
 * @author    LindemannRock
 * @package   DocsManager
 * @since     5.0.0
 */
class SourceDoc extends Element
{
    // Non-translatable properties (main table)
    // =========================================================================

    /**
     * @var int|null Source record ID
     */
    public ?int $sourceId = null;

    /**
     * @var string|null Page slug (e.g., "get-started/installation")
     */
    public ?string $slug = null;

    /**
     * @var string|null Category key (e.g., "get-started")
     */
    public ?string $category = null;

    /**
     * @var int Sort order
     */
    public int $order = 0;

    // Translatable properties (content table)
    // =========================================================================

    /**
     * @var string|null Page description
     */
    public ?string $description = null;

    /**
     * @var string|null Original markdown source
     */
    public ?string $markdownSource = null;

    /**
     * @var string|null Parsed HTML content
     */
    public ?string $htmlContent = null;

    /**
     * @var array|null H2/H3 headings for anchor navigation
     */
    public ?array $headings = null;

    /**
     * @var array|null Search keywords
     */
    public ?array $keywords = null;

    /**
     * @var array|null Additional frontmatter metadata
     */
    public ?array $metadata = null;

    /**
     * @var string|null Last sync timestamp
     */
    public ?string $lastSyncedAt = null;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('docs-manager', 'Doc Page');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('docs-manager', 'doc page');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('docs-manager', 'Doc Pages');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('docs-manager', 'doc pages');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'sourceDoc';
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
    public static function hasContent(): bool
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
        return false;
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
    public static function hasDrafts(): bool
    {
        return false;
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
     * @return SourceDocQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new SourceDocQuery(static::class);
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
    public function __toString(): string
    {
        return $this->title ?? $this->slug ?? '';
    }

    /**
     * @inheritdoc
     */
    public function getUrl(): ?string
    {
        $handle = $this->getSourceHandle();
        if (!$handle || !$this->slug) {
            return null;
        }

        return '/plugins/' . $handle . '/docs/' . $this->slug;
    }

    /**
     * Get the handle for this doc's parent source
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
     * @var string|null Cached source handle
     */
    private ?string $_sourceHandle = null;

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        return $user->can('docsManager:editSources');
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        return $user->can('docsManager:deleteSources');
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
    protected static function defineSearchableAttributes(): array
    {
        return ['slug', 'category', 'description'];
    }

    /**
     * @inheritdoc
     */
    public function getSearchKeywords(string $attribute): string
    {
        if ($attribute === 'description') {
            return strip_tags($this->description ?? '');
        }

        return parent::getSearchKeywords($attribute);
    }

    /**
     * @inheritdoc
     */
    public function afterPopulate(): void
    {
        $this->loadContent();
    }

    /**
     * Load translatable content from the content table for the current site
     */
    public function loadContent(): void
    {
        if (!$this->id || !$this->siteId) {
            return;
        }

        $contentRecord = DocPageContentRecord::findOne([
            'pageId' => $this->id,
            'siteId' => $this->siteId,
        ]);

        if ($contentRecord) {
            if (empty($this->title) && $contentRecord->title) {
                $this->title = $contentRecord->title;
            }
            $this->description = $contentRecord->description;
            $this->markdownSource = $contentRecord->markdownSource;
            $this->htmlContent = $contentRecord->htmlContent;
            $this->headings = is_string($contentRecord->headings) ? json_decode($contentRecord->headings, true) : $contentRecord->headings;
            $this->keywords = is_string($contentRecord->keywords) ? json_decode($contentRecord->keywords, true) : $contentRecord->keywords;
            $this->metadata = is_string($contentRecord->metadata) ? json_decode($contentRecord->metadata, true) : $contentRecord->metadata;
            $this->lastSyncedAt = $contentRecord->lastSyncedAt;
        }
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->getIsRevision() && !$this->resaving) {
            if (!$isNew) {
                $record = DocPageRecord::findOne($this->id);
                if (!$record) {
                    throw new \Exception('Invalid source doc ID: ' . $this->id);
                }
            } else {
                $record = new DocPageRecord();
                $record->id = $this->id;
            }

            $record->sourceId = $this->sourceId;
            $record->category = $this->category;
            $record->slug = $this->slug;
            $record->order = $this->order;

            $record->save(false);

            $contentRecord = DocPageContentRecord::findOne([
                'pageId' => $this->id,
                'siteId' => $this->siteId,
            ]);

            if (!$contentRecord) {
                $contentRecord = new DocPageContentRecord();
                $contentRecord->pageId = $this->id;
                $contentRecord->siteId = $this->siteId;
            }

            $contentRecord->title = $this->title ?? '';
            $contentRecord->description = $this->description;
            $contentRecord->markdownSource = $this->markdownSource ?? '';
            $contentRecord->htmlContent = $this->htmlContent ?? '';
            $contentRecord->headings = is_array($this->headings) ? json_encode($this->headings) : $this->headings;
            $contentRecord->keywords = is_array($this->keywords) ? json_encode($this->keywords) : $this->keywords;
            $contentRecord->metadata = is_array($this->metadata) ? json_encode($this->metadata) : $this->metadata;
            $contentRecord->lastSyncedAt = $this->lastSyncedAt;

            $contentRecord->save(false);
        }

        parent::afterSave($isNew);
    }
}
