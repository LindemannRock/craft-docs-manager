<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\records;

use Craft;
use craft\db\ActiveRecord;
use lindemannrock\base\helpers\SlugHandleHelper;
use yii\db\ActiveQueryInterface;

/**
 * Source Version Record
 *
 * @property int $id
 * @property int $sourceId
 * @property string $label
 * @property string|null $slug
 * @property string $ref
 * @property string $status
 * @property bool $isDefault
 * @property int $sortOrder
 * @property string $displayStatus
 * @property string $displayStatusLabel
 * @property string|null $lastSyncedAt
 * @property string|null $lastSyncStatus
 * @property string|null $lastSyncError
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @since 5.2.0
 */
class SourceVersionRecord extends ActiveRecord
{
    public const STATUS_LATEST = 'latest';
    public const STATUS_STABLE = 'stable';
    public const STATUS_BETA = 'beta';
    public const STATUS_ALPHA = 'alpha';
    public const STATUS_RETIRED = 'retired';

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%docsmanager_source_versions}}';
    }

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool
    {
        $this->label = trim((string) $this->label);
        $this->ref = trim((string) $this->ref);

        if ($this->slug !== null) {
            $slug = SlugHandleHelper::normalizePathSlug((string) $this->slug, '');
            $this->slug = $slug === '' ? null : $slug;
        }

        if ((bool) $this->isDefault) {
            $this->slug = null;
            $this->ref = 'main';
            $this->status = self::STATUS_LATEST;
        } elseif ($this->status !== self::STATUS_RETIRED && $this->status !== self::STATUS_LATEST) {
            $this->status = self::STATUS_STABLE;
        }

        return parent::beforeValidate();
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['sourceId', 'label', 'ref', 'status'], 'required'],
            [['sourceId', 'sortOrder'], 'integer'],
            [['isDefault'], 'boolean'],
            [['label', 'slug', 'ref', 'lastSyncStatus'], 'string', 'max' => 255],
            [['lastSyncError'], 'string'],
            [['status'], 'in', 'range' => array_keys(self::statusOptions())],
            [['slug'], 'match', 'pattern' => '/^v\d+(?:-(?:alpha|beta))?$/', 'message' => Craft::t('docs-manager', 'Version URL slug must look like v5, v6-beta, or v6-alpha.')],
            [['ref'], 'match', 'pattern' => '/^[A-Za-z0-9._\/-]+$/', 'message' => Craft::t('docs-manager', 'Git ref contains invalid characters.')],
            [['slug'], 'validateSlugRequirement'],
            [['isDefault'], 'validateDefaultPolicy'],
            [['status'], 'validateStatusPolicy'],
            [['slug'], 'unique', 'targetAttribute' => ['sourceId', 'slug'], 'filter' => fn($query) => $query->andWhere(['not', ['slug' => null]])],
            [['isDefault'], 'unique', 'targetAttribute' => ['sourceId', 'isDefault'], 'filter' => fn($query) => $query->andWhere(['isDefault' => true])],
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_LATEST => Craft::t('docs-manager', 'Latest'),
            self::STATUS_STABLE => Craft::t('docs-manager', 'Stable'),
            self::STATUS_BETA => Craft::t('docs-manager', 'Beta'),
            self::STATUS_ALPHA => Craft::t('docs-manager', 'Alpha'),
            self::STATUS_RETIRED => Craft::t('docs-manager', 'Retired'),
        ];
    }

    public function getDisplayStatus(): string
    {
        if ((bool) $this->isDefault) {
            return self::STATUS_LATEST;
        }

        if ($this->status === self::STATUS_RETIRED) {
            return self::STATUS_RETIRED;
        }

        $value = strtolower(implode(' ', [
            (string) $this->label,
            (string) $this->slug,
            (string) $this->ref,
            (string) $this->status,
        ]));

        if (str_contains($value, 'alpha')) {
            return self::STATUS_ALPHA;
        }

        if (str_contains($value, 'beta')) {
            return self::STATUS_BETA;
        }

        return self::STATUS_STABLE;
    }

    public function getDisplayStatusLabel(): string
    {
        return self::statusOptions()[$this->getDisplayStatus()] ?? '';
    }

    public function validateSlugRequirement(string $attribute): void
    {
        if ((bool) $this->isDefault) {
            return;
        }

        if ($this->$attribute === null || $this->$attribute === '') {
            $this->addError($attribute, Craft::t('docs-manager', 'Non-default versions require a URL slug.'));
        }
    }

    public function validateDefaultPolicy(string $attribute): void
    {
        if (!(bool) $this->$attribute) {
            return;
        }

        if ($this->ref !== 'main') {
            $this->addError('ref', Craft::t('docs-manager', 'The default docs version must use the main ref.'));
        }

        if ($this->slug !== null) {
            $this->addError('slug', Craft::t('docs-manager', 'The default docs version cannot have a URL slug.'));
        }

        if ($this->status !== self::STATUS_LATEST) {
            $this->addError('status', Craft::t('docs-manager', 'The default docs version must be Latest.'));
        }
    }

    public function validateStatusPolicy(string $attribute): void
    {
        if ((bool) $this->isDefault) {
            return;
        }

        if ($this->$attribute === self::STATUS_LATEST) {
            $this->addError($attribute, Craft::t('docs-manager', 'Only the default docs version can be Latest.'));
        }
    }

    public function getSource(): ActiveQueryInterface
    {
        return $this->hasOne(SourceRecord::class, ['id' => 'sourceId']);
    }
}
