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
use lindemannrock\docsmanager\elements\PluginPage;

/**
 * PluginPageQuery element query
 *
 * @method PluginPage[]|array all($db = null)
 * @method PluginPage|array|null one($db = null)
 * @method PluginPage|array|null nth(int $n, ?Connection $db = null)
 * @since 5.0.0
 */
class PluginPageQuery extends ElementQuery
{
    // Properties
    // =========================================================================

    /**
     * @var int|int[]|null The source ID(s) that the resulting pages must have.
     */
    public mixed $sourceId = null;

    /**
     * @var string|string[]|null The source handle(s) that the resulting pages must belong to.
     */
    public mixed $sourceHandle = null;

    /**
     * @var string|string[]|null The page type(s) that the resulting pages must have.
     */
    public mixed $pageType = null;

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
     * Sets the [[pageType]] property.
     *
     * @param string|string[]|null $value
     * @return static
     */
    public function pageType(mixed $value): static
    {
        $this->pageType = $value;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('docsmanager_custom_pages');

        $this->query->select([
            'docsmanager_custom_pages.sourceId',
            'docsmanager_custom_pages.pageType',
            'docsmanager_custom_pages.slug',
            'docsmanager_custom_pages.order',
        ]);

        if ($this->sourceId) {
            $this->subQuery->andWhere(Db::parseParam('docsmanager_custom_pages.sourceId', $this->sourceId));
        }

        if ($this->sourceHandle) {
            $this->subQuery->innerJoin(
                '{{%docsmanager_sources}} docsmanager_sources',
                '[[docsmanager_sources.id]] = [[docsmanager_custom_pages.sourceId]]'
            );
            $this->subQuery->andWhere(Db::parseParam('docsmanager_sources.handle', $this->sourceHandle));
        }

        if ($this->pageType) {
            $this->subQuery->andWhere(Db::parseParam('docsmanager_custom_pages.pageType', $this->pageType));
        }

        return parent::beforePrepare();
    }
}
