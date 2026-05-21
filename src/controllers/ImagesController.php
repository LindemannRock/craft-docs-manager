<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\controllers;

use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Images Controller
 *
 * Serves documentation images from a plugin's docs/images/ directory.
 *
 * Request URL: /plugins/{handle}/docs/images/{path}
 * On disk:     @root/plugins/{handle}/docs/images/{path}
 *
 * @since 5.0.0
 */
class ImagesController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    private const ALLOWED_EXTENSIONS = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    public function actionServe(string $handle, string $path): Response
    {
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $handle)) {
            throw new NotFoundHttpException();
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_EXTENSIONS[$ext])) {
            throw new NotFoundHttpException();
        }

        $root = Craft::getAlias('@root');
        if (!is_string($root)) {
            throw new NotFoundHttpException();
        }

        $baseDir = realpath($root . '/plugins/' . $handle . '/docs/images');
        if ($baseDir === false) {
            throw new NotFoundHttpException();
        }

        $target = realpath($baseDir . '/' . $path);
        if ($target === false || !is_file($target)) {
            throw new NotFoundHttpException();
        }

        // Path traversal guard — target must live inside baseDir
        if (!str_starts_with($target, $baseDir . DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException();
        }

        $mtime = filemtime($target);
        $etag = '"' . md5($target . '|' . $mtime) . '"';

        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();

        $ifNoneMatch = $request->getHeaders()->get('If-None-Match');
        if ($ifNoneMatch === $etag) {
            $response->setStatusCode(304);
            return $response;
        }

        $response->getHeaders()->set('Content-Type', self::ALLOWED_EXTENSIONS[$ext]);
        $response->getHeaders()->set('Cache-Control', 'public, max-age=86400');
        $response->getHeaders()->set('ETag', $etag);
        $response->getHeaders()->set('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        return $response->sendFile($target, basename($target), [
            'inline' => true,
            'mimeType' => self::ALLOWED_EXTENSIONS[$ext],
        ]);
    }
}
