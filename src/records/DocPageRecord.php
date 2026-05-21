<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Doc Page Record
 *
 * Non-translatable element data. The `id` column is a FK to `elements.id`.
 * Translatable content (title, description, HTML, etc.) is stored in
 * {@see DocPageContentRecord}.
 *
 * @property int $id FK to elements.id
 * @property int $sourceId
 * @property string $category
 * @property string $slug
 * @property int $order
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @since 5.0.0
 */
class DocPageRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%docsmanager_pages}}';
    }

    /**
     * Returns the doc page's source.
     *
     * @return ActiveQueryInterface
     */
    public function getSource(): ActiveQueryInterface
    {
        return $this->hasOne(SourceRecord::class, ['id' => 'sourceId']);
    }
}
