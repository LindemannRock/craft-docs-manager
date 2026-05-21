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
use craft\helpers\App;

/**
 * Source Record
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $kind
 * @property string|null $description
 * @property string|null $iconSvg
 * @property string $sourceType
 * @property string|null $repositoryUrl
 * @property string|null $localPath
 * @property string|null $currentVersion
 * @property string|null $releaseDate
 * @property bool $enabled
 * @property string|null $lastSyncedAt
 * @property string|null $changelogContent
 * @property array|null $metadata
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @since 5.0.0
 */
class SourceRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%docsmanager_sources}}';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['name', 'handle', 'kind', 'sourceType'], 'required'],
            [['name', 'handle', 'description', 'repositoryUrl', 'localPath'], 'filter', 'filter' => 'trim'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['repositoryUrl', 'localPath'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z0-9_\-]+$/', 'message' => Craft::t('docs-manager', '{attribute} should only contain letters, numbers, underscores, and hyphens.')],
            [['handle'], 'unique', 'targetClass' => self::class, 'message' => Craft::t('docs-manager', '{attribute} "{value}" is already in use.')],
            [['kind'], 'in', 'range' => ['plugin', 'theme']],
            [['sourceType'], 'in', 'range' => ['local', 'github-api']],
            [['localPath'], 'required', 'when' => fn(self $model): bool => $model->sourceType === 'local'],
            [['repositoryUrl'], 'required', 'when' => fn(self $model): bool => $model->sourceType === 'github-api'],
            [['repositoryUrl'], 'validateRepositoryUrl'],
            [['localPath'], 'validateLocalPath'],
        ];
    }

    public function validateRepositoryUrl(string $attribute): void
    {
        $value = trim((string) $this->$attribute);
        if ($value === '' || $this->sourceType !== 'github-api') {
            return;
        }

        if (preg_match('#^https://github\.com/[^/\s]+/[^/\s]+(?:\.git)?/?$#i', $value) === 1) {
            return;
        }

        $this->addError(
            $attribute,
            Craft::t('docs-manager', 'Repository URL must be a valid GitHub repository URL (for example: https://github.com/owner/repo).')
        );
    }

    public function validateLocalPath(string $attribute): void
    {
        $value = trim((string) $this->$attribute);
        if ($value === '' || $this->sourceType !== 'local') {
            return;
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $value) === 1 || str_contains($value, "\0")) {
            $this->addError($attribute, Craft::t('docs-manager', 'Local path contains invalid path traversal.'));
            return;
        }

        $parsed = App::parseEnv($value);
        if (!is_string($parsed) || trim($parsed) === '') {
            $this->addError($attribute, Craft::t('docs-manager', 'Local path is invalid.'));
            return;
        }

        $resolved = trim($parsed);
        if (str_starts_with($resolved, '@')) {
            try {
                $resolved = trim(Craft::getAlias($resolved));
            } catch (\Throwable) {
                $this->addError($attribute, Craft::t('docs-manager', 'Local path alias could not be resolved.'));
                return;
            }
        }

        if ($resolved === '') {
            $this->addError($attribute, Craft::t('docs-manager', 'Local path is invalid.'));
            return;
        }

        if (
            !str_starts_with($resolved, '/')
            && preg_match('/^[A-Za-z]:[\\\\\\/]/', $resolved) !== 1
        ) {
            $this->addError($attribute, Craft::t('docs-manager', 'Local path must be an absolute path or valid alias.'));
            return;
        }

        if (!is_dir($resolved)) {
            $this->addError($attribute, Craft::t('docs-manager', 'Local path does not exist: {path}', ['path' => $resolved]));
        }
    }
}
