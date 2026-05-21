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
 * Plugin Page Record
 *
 * ActiveRecord for the `docsmanager_custom_pages` table.
 * The `id` column is a FK to `elements.id`.
 *
 * @property int $id FK to elements.id
 * @property int $sourceId
 * @property string $pageType
 * @property string $slug
 * @property int $order
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @since 5.0.0
 */
class PluginPageRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%docsmanager_custom_pages}}';
    }

    /**
     * Returns the page's source.
     *
     * @return ActiveQueryInterface
     */
    public function getSource(): ActiveQueryInterface
    {
        return $this->hasOne(SourceRecord::class, ['id' => 'sourceId']);
    }
}
