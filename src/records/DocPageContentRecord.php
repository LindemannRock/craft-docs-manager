<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\records;

use craft\db\ActiveRecord;

/**
 * Doc Page Content Record
 *
 * Stores site-specific/translatable content for doc pages.
 *
 * @property int $id
 * @property int $pageId
 * @property int $siteId
 * @property string $title
 * @property string|null $description
 * @property string $markdownSource
 * @property string $htmlContent
 * @property string|null $headings
 * @property string|null $keywords
 * @property string|null $lastSyncedAt
 * @property string|null $metadata
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @since 5.0.0
 */
class DocPageContentRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%docsmanager_pages_content}}';
    }
}
