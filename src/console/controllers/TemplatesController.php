<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use yii\console\ExitCode;

/**
 * Template installation and management
 *
 * @since 5.0.0
 */
class TemplatesController extends Controller
{
    /**
     * Install example frontend templates to your templates directory
     *
     * Usage: php craft docs-manager/templates/install
     */
    public function actionInstall(): int
    {
        $this->stdout("Docs Manager - Install Example Templates\n\n", Console::FG_CYAN);

        // Ask where to install
        $defaultPath = '@root/templates/plugins';
        $destinationInput = $this->prompt(
            "Where should we install the templates? (default: templates/plugins)",
            ['default' => 'templates/plugins']
        );

        $destination = Craft::getAlias('@root/' . trim($destinationInput, '/'));

        $this->stdout("\nInstalling to: {$destination}\n\n", Console::FG_YELLOW);

        // Get source path (examples folder in plugin)
        $sourcePath = Craft::getAlias('@root/plugins/docs-manager/examples/templates');

        if (!is_dir($sourcePath)) {
            $this->stderr("Error: Example templates not found at {$sourcePath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Create destination directory
        if (!is_dir($destination)) {
            FileHelper::createDirectory($destination);
            $this->stdout("✓ Created directory: {$destination}\n", Console::FG_GREEN);
        }

        // Copy files
        $files = [
            '_layout.twig' => '_layout.twig',
            'plugin-index.twig' => 'index.twig',
            'plugin-detail.twig' => '_plugin.twig',
            'doc-page.twig' => '_doc.twig',
            'changelog.twig' => '_changelog.twig',
        ];

        foreach ($files as $source => $dest) {
            $sourceFile = $sourcePath . '/' . $source;
            $destFile = $destination . '/' . $dest;

            if (file_exists($destFile)) {
                $overwrite = $this->confirm("  {$dest} already exists. Overwrite?");
                if (!$overwrite) {
                    $this->stdout("  ⊘ Skipped: {$dest}\n", Console::FG_YELLOW);
                    continue;
                }
            }

            copy($sourceFile, $destFile);
            $this->stdout("  ✓ Copied: {$dest}\n", Console::FG_GREEN);
        }

        $this->stdout("\n✅ Templates installed successfully!\n\n", Console::FG_GREEN);

        // Install routes
        if ($this->confirm("Install routes to config/routes.php?", true)) {
            $this->installRoutes();
        }

        // Show next steps
        $this->stdout("\nNext steps:\n", Console::FG_YELLOW);
        $this->stdout("1. Customize templates/plugins/_layout.twig with your site design\n");
        $this->stdout("2. Visit /plugins to see your documentation site\n\n");

        $this->stdout("Example URLs:\n", Console::FG_CYAN);
        $this->stdout("  /plugins - All plugins\n");
        $this->stdout("  /plugins/translation-manager - Plugin detail\n");
        $this->stdout("  /plugins/translation-manager/docs/installation - Doc page\n\n");

        return ExitCode::OK;
    }

    /**
     * Install routes to config/routes.php
     */
    protected function installRoutes(): void
    {
        $routesPath = Craft::getAlias('@root/config/routes.php');

        // Routes to add
        $newRoutes = [
            "'plugins' => ['template' => 'plugins/index']," => true,
            "'plugins/<handle:{slug}>' => ['template' => 'plugins/pages/plugin']," => true,
            "'plugins/<handle:{slug}>/changelog' => ['template' => 'plugins/pages/changelog']," => true,
            "'plugins/<handle:{slug}>/docs/<slug:{slug}>' => ['template' => 'plugins/pages/doc']," => true,
        ];

        // Check if routes.php exists
        if (!file_exists($routesPath)) {
            // Create new routes.php
            $content = "<?php\nreturn [\n";
            foreach ($newRoutes as $route => $add) {
                $content .= "    {$route}\n";
            }
            $content .= "];\n";

            file_put_contents($routesPath, $content);
            $this->stdout("\n✓ Created config/routes.php with plugin routes\n", Console::FG_GREEN);
            return;
        }

        // Read existing routes.php
        $content = file_get_contents($routesPath);

        // Check which routes are missing
        $routesToAdd = [];
        foreach ($newRoutes as $route => $add) {
            if (!str_contains($content, $route)) {
                $routesToAdd[] = $route;
            }
        }

        if (empty($routesToAdd)) {
            $this->stdout("\n✓ Routes already exist in config/routes.php\n", Console::FG_GREEN);
            return;
        }

        // Add missing routes before the closing bracket
        $routesCode = "\n    // Docs Manager routes\n";
        foreach ($routesToAdd as $route) {
            $routesCode .= "    {$route}\n";
        }

        // Insert before the final ];
        $content = preg_replace('/\];(\s*)$/', $routesCode . '];$1', $content);

        file_put_contents($routesPath, $content);
        $this->stdout("\n✓ Added " . count($routesToAdd) . " route(s) to config/routes.php\n", Console::FG_GREEN);
    }
}
