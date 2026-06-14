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
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\helpers\LocalSourcePathHelper;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\docsmanager\records\SourceVersionRecord;
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

    public function actionServe(string $handle, string $path, ?string $version = null): Response
    {
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $handle)) {
            throw new NotFoundHttpException();
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_EXTENSIONS[$ext])) {
            throw new NotFoundHttpException();
        }

        if ($version !== null && $version !== '') {
            return $this->serveVersionedImage($handle, $version, $path);
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

    private function serveVersionedImage(string $handle, string $version, string $path): Response
    {
        if (!preg_match('/^v\d+(?:-(?:alpha|beta))?$/', $version)) {
            throw new NotFoundHttpException();
        }

        $source = SourceRecord::findOne(['handle' => $handle]);
        if (!$source) {
            throw new NotFoundHttpException();
        }

        $sourceVersion = SourceVersionRecord::findOne([
            'sourceId' => $source->id,
            'slug' => $version,
        ]);
        if (!$sourceVersion) {
            throw new NotFoundHttpException();
        }

        if ($source->sourceType === 'local') {
            return $this->serveLocalGitImage($source, $sourceVersion, $path);
        }

        if (!$source->repositoryUrl || !preg_match('#github\.com/([^/]+)/([^/]+)#', $source->repositoryUrl, $m)) {
            throw new NotFoundHttpException();
        }

        $owner = $m[1];
        $repo = rtrim($m[2], '/');
        $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$sourceVersion->ref}/docs/images/" . str_replace('%2F', '/', rawurlencode($path));

        return $this->redirect($url);
    }

    private function serveLocalGitImage(SourceRecord $source, SourceVersionRecord $sourceVersion, string $path): Response
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || str_contains($path, "\0") || str_starts_with($path, '/')) {
            throw new NotFoundHttpException();
        }

        if ($source->localPath) {
            $sourcePath = LocalSourcePathHelper::resolve($source->localPath);
        } else {
            $settings = DocsManager::getInstance()->getSettings();
            $sourcePath = LocalSourcePathHelper::join((string) $settings->localPluginBasePath, $source->handle);
        }

        if (!is_dir($sourcePath) || preg_match('/^[A-Za-z0-9._\/-]+$/', $sourceVersion->ref) !== 1) {
            throw new NotFoundHttpException();
        }

        $spec = $sourceVersion->ref . ':docs/images/' . $path;
        $command = sprintf('git -C %s show %s', escapeshellarg($sourcePath), escapeshellarg($spec));
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new NotFoundHttpException();
        }

        $content = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_string($content)) {
            throw new NotFoundHttpException();
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', self::ALLOWED_EXTENSIONS[$ext]);
        $response->getHeaders()->set('Cache-Control', 'public, max-age=86400');
        $response->content = $content;

        return $response;
    }
}
