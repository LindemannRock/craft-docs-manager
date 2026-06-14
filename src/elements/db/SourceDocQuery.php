<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use lindemannrock\docsmanager\elements\SourceDoc;

/**
 * SourceDocQuery element query
 *
 * @method SourceDoc[]|array all($db = null)
 * @method SourceDoc|array|null one($db = null)
 * @method SourceDoc|array|null nth(int $n, ?Connection $db = null)
 * @since 5.0.0
 */
class SourceDocQuery extends ElementQuery
{
    // Properties
    // =========================================================================

    /**
     * @var int|int[]|null The source ID(s) that the resulting docs must have.
     */
    public mixed $sourceId = null;

    /**
     * @var string|string[]|null The source handle(s) that the resulting docs must belong to.
     */
    public mixed $sourceHandle = null;

    /**
     * @var string|string[]|null The category(ies) that the resulting docs must have.
     */
    public mixed $category = null;

    /**
     * @var string|string[]|null The version slug(s) that the resulting docs must belong to. Empty string is default/latest.
     * @since 5.2.0
     */
    public mixed $version = null;

    /**
     * @var string|string[]|null The slug(s) that the resulting docs must have.
     */
    public mixed $slug = null;

    /**
     * @var bool|null Whether the resulting docs must have HTML content.
     */
    public ?bool $hasContent = null;

    // Public Methods
    // =========================================================================

    /**
     * Sets the [[sourceId]] property.
     *
     * @param int|int[]|null $value
     * @return static
     */
    public function sourceId(mixed $value): static
    {
        $this->sourceId = $value;
        return $this;
    }

    /**
     * Sets the [[sourceHandle]] property.
     *
     * @param string|string[]|null $value
     * @return static
     */
    public function sourceHandle(mixed $value): static
    {
        $this->sourceHandle = $value;
        return $this;
    }

    /**
     * Sets the [[category]] property.
     *
     * @param string|string[]|null $value
     * @return static
     */
    public function category(mixed $value): static
    {
        $this->category = $value;
        return $this;
    }

    /**
     * Sets the [[version]] property.
     *
     * @param string|string[]|null $value
     * @return static
     * @since 5.2.0
     */
    public function version(mixed $value): static
    {
        $this->version = $value;
        return $this;
    }

    /**
     * Sets the [[slug]] property.
     *
     * @param string|string[]|null $value
     * @return static
     */
    public function slug(mixed $value): static
    {
        $this->slug = $value;
        return $this;
    }

    /**
     * Sets the [[hasContent]] property.
     *
     * @param bool|null $value
     * @return static
     */
    public function hasContent(?bool $value = true): static
    {
        $this->hasContent = $value;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('docsmanager_pages');

        $this->query->leftJoin(
            '{{%docsmanager_pages_content}} docsmanager_pages_content',
            '[[docsmanager_pages_content.pageId]] = [[elements.id]] AND [[docsmanager_pages_content.siteId]] = [[elements_sites.siteId]]'
        );

        $this->query->select([
            'docsmanager_pages.sourceId',
            'docsmanager_pages.version',
            'docsmanager_pages.category',
            'docsmanager_pages.slug',
            'docsmanager_pages.order',
            'docsmanager_pages_content.description',
            'docsmanager_pages_content.markdownSource',
            'docsmanager_pages_content.htmlContent',
            'docsmanager_pages_content.headings',
            'docsmanager_pages_content.keywords',
            'docsmanager_pages_content.lastSyncedAt',
            'docsmanager_pages_content.metadata',
        ]);

        if ($this->sourceId) {
            $this->subQuery->andWhere(Db::parseParam('docsmanager_pages.sourceId', $this->sourceId));
        }

        if ($this->sourceHandle) {
            $this->subQuery->innerJoin(
                '{{%docsmanager_sources}} docsmanager_sources',
                '[[docsmanager_sources.id]] = [[docsmanager_pages.sourceId]]'
            );
            $this->subQuery->andWhere(Db::parseParam('docsmanager_sources.handle', $this->sourceHandle));
        }

        if ($this->category) {
            $this->subQuery->andWhere(Db::parseParam('docsmanager_pages.category', $this->category));
        }

        if ($this->version !== null) {
            if ($this->version === '') {
                $this->subQuery->andWhere(['docsmanager_pages.version' => '']);
            } else {
                $this->subQuery->andWhere(Db::parseParam('docsmanager_pages.version', $this->version));
            }
        }

        if ($this->slug) {
            $this->subQuery->andWhere(Db::parseParam('docsmanager_pages.slug', $this->slug));
        }

        if ($this->hasContent !== null) {
            if ($this->hasContent) {
                $this->subQuery->innerJoin(
                    '{{%docsmanager_pages_content}} ppc_filter',
                    '[[ppc_filter.pageId]] = [[elements.id]]'
                );
                $this->subQuery->andWhere(['not', ['ppc_filter.htmlContent' => null]]);
                $this->subQuery->andWhere(['not', ['ppc_filter.htmlContent' => '']]);
            }
        }

        return parent::beforePrepare();
    }
}
