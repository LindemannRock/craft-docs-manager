<?php
/**
 * LindemannRock Docs Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\tests\Integration;

use lindemannrock\docsmanager\services\SyncService;
use lindemannrock\docsmanager\tests\TestCase;

/**
 * Pins the narrow classifier used by sync-save retry handling.
 *
 * Craft invalidates element cache tags after saving SourceDoc elements. With
 * Yii Redis cache and expiring cache writes, that invalidation can
 * intermittently throw "Undefined array key N" from yii2-redis while processing
 * the Redis transaction result. Docs Manager may retry that infrastructure
 * failure, but must not hide unrelated undefined-index or save failures.
 *
 * @since 5.4.0
 */
final class SyncRedisInvalidationRetryTest extends TestCase
{
    private SyncService $publicSync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicSync = new class () extends SyncService {
            public function isRedisTagInvalidationFailurePublic(string $message, string $trace): bool
            {
                return $this->isRedisTagInvalidationFailure($message, $trace);
            }
        };
    }

    public function testRedisTagInvalidationFailureIsRetryable(): void
    {
        $trace = <<<'TRACE'
#0 /var/www/html/vendor/yiisoft/yii2-redis/src/Cache.php(288): yii\base\ErrorHandler->handleError()
#1 /var/www/html/vendor/yiisoft/yii2/caching/Cache.php(299): yii\redis\Cache->setValues()
#2 /var/www/html/vendor/yiisoft/yii2/caching/TagDependency.php(96): yii\caching\TagDependency::touchKeys()
TRACE;

        self::assertTrue($this->publicSync->isRedisTagInvalidationFailurePublic('Undefined array key 2', $trace));
    }

    public function testOtherUndefinedArrayKeyFailuresAreNotRetryable(): void
    {
        $trace = <<<'TRACE'
#0 /var/www/html/plugins/docs-manager/src/services/ParserService.php(100): yii\base\ErrorHandler->handleError()
#1 /var/www/html/plugins/docs-manager/src/services/SyncService.php(320): ParserService->parseMarkdown()
TRACE;

        self::assertFalse($this->publicSync->isRedisTagInvalidationFailurePublic('Undefined array key 2', $trace));
    }

    public function testOtherRedisFailuresAreNotRetryable(): void
    {
        $trace = <<<'TRACE'
#0 /var/www/html/vendor/yiisoft/yii2-redis/src/Cache.php(288): yii\base\ErrorHandler->handleError()
#1 /var/www/html/vendor/yiisoft/yii2/caching/TagDependency.php(96): yii\caching\TagDependency::touchKeys()
TRACE;

        self::assertFalse($this->publicSync->isRedisTagInvalidationFailurePublic('Connection refused', $trace));
    }
}
