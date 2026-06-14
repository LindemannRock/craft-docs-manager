<?php
/**
 * Docs Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\docsmanager\console\controllers;

use lindemannrock\base\console\controllers\AbstractHelpController;

/**
 * Console help for Docs Manager commands.
 *
 * @since 5.1.0
 */
final class HelpController extends AbstractHelpController
{
    /**
     * @inheritdoc
     */
    protected function helpManifest(): array
    {
        return [
            'title' => 'Docs Manager',
            'pluginHandle' => 'docs-manager',
            'commandPrefixes' => [
                'php craft',
                'ddev craft',
            ],
            'summary' => 'Use these commands to generate plugin docs, migrate READMEs, generate README hero banners, sync documentation sources, inspect detected versions, test markdown parsing, and install starter frontend templates.',
            'common' => [
                'sync/plugin',
                'sync',
                'docs/create',
                'docs/migrate',
                'sync/version',
            ],
            'groups' => [
                [
                    'name' => 'docs',
                    'label' => 'Generation',
                    'description' => 'Generate docs skeletons and migrate README files.',
                    'commands' => [
                        [
                            'path' => 'docs/create',
                            'summary' => 'Generate auto-doc files from plugin source.',
                            'description' => 'Scan a plugin source tree and generate developer docs such as configuration, permissions, console commands, events, template variables, Twig globals, shared features, sidebar metadata, and plugin metadata.',
                            'usageOptions' => '[--plugin=<plugin>] [--force] [--dry-run] [--verbose]',
                            'options' => [
                                [
                                    'name' => '--plugin',
                                    'description' => 'Plugin handle or folder name. Omit when running from inside a plugin directory.',
                                ],
                                [
                                    'name' => '--force',
                                    'description' => 'Overwrite existing generated files.',
                                ],
                                [
                                    'name' => '--dry-run',
                                    'description' => 'Preview what would be generated without writing files.',
                                ],
                                [
                                    'name' => '--verbose',
                                    'description' => 'Show extraction counts for settings, variables, commands, and related surfaces.',
                                ],
                            ],
                            'examples' => [
                                'docs-manager/docs/create --plugin=search-manager',
                                'docs-manager/docs/create --plugin=search-manager --dry-run --verbose',
                            ],
                        ],
                        [
                            'path' => 'docs/migrate',
                            'summary' => 'Migrate README sections into docs files.',
                            'description' => 'Parse README headings and write structured docs under the plugin docs folder. Existing target files are skipped.',
                            'usageOptions' => '[--plugin=<plugin>] [--dry-run] [--verbose]',
                            'options' => [
                                [
                                    'name' => '--plugin',
                                    'description' => 'Plugin handle or folder name. Omit when running from inside a plugin directory.',
                                ],
                                [
                                    'name' => '--dry-run',
                                    'description' => 'Preview the migration without writing files.',
                                ],
                                [
                                    'name' => '--verbose',
                                    'description' => 'Show section-by-section extraction details.',
                                ],
                            ],
                            'examples' => [
                                'docs-manager/docs/migrate --plugin=search-manager --dry-run',
                                'docs-manager/docs/migrate --plugin=search-manager',
                            ],
                            'notes' => [
                                'The migrate command skips files that already exist.',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'hero',
                    'label' => 'Hero Banners',
                    'description' => 'Generate README hero banners from plugin icons and metadata.',
                    'commands' => [
                        [
                            'path' => 'hero/generate',
                            'summary' => 'Generate a README hero banner for a plugin.',
                            'description' => 'Compose a 1280x360 hero.webp derived entirely from the plugin icon: a smooth gradient tinted by the icon accent colour, the title set in the icon glyph colour, an accent-tinted shadow, plus the composer name and tagline. This is an authoring command — it requires the ImageMagick CLI and is never used at runtime.',
                            'arguments' => '<plugin>',
                            'usageOptions' => '[out] [--name=<name>] [--tagline=<text>] [--style=<style>]',
                            'options' => [
                                [
                                    'name' => '--name',
                                    'description' => 'Override the banner title. Defaults to composer extra.name.',
                                ],
                                [
                                    'name' => '--tagline',
                                    'description' => 'Override the tagline. Defaults to composer description trimmed at the first " - ".',
                                ],
                                [
                                    'name' => '--style',
                                    'description' => 'Gradient style: primary, lighter (default), deeper, or diagonal.',
                                ],
                            ],
                            'examples' => [
                                'docs-manager/hero/generate search-manager',
                                'docs-manager/hero/generate search-manager /tmp/hero-check.webp',
                            ],
                            'notes' => [
                                'Requires the ImageMagick CLI (magick) on PATH.',
                                'The icon is read from disk, so the plugin does not need to be installed or enabled.',
                                'Defaults to {plugin}/docs/images/hero.webp when no output path is given.',
                            ],
                        ],
                        [
                            'path' => 'hero/generate-all',
                            'summary' => 'Generate hero banners for all plugins with docs.',
                            'description' => 'Generate banners for every plugin or module on disk that has a docs folder and an icon. Existing banners are skipped unless --force is given.',
                            'usageOptions' => '[--force] [--style=<style>]',
                            'options' => [
                                [
                                    'name' => '--force',
                                    'description' => 'Overwrite banners that already exist.',
                                ],
                                [
                                    'name' => '--style',
                                    'description' => 'Gradient style: primary, lighter (default), deeper, or diagonal.',
                                ],
                            ],
                            'examples' => [
                                'docs-manager/hero/generate-all',
                                'docs-manager/hero/generate-all --force',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'sync',
                    'label' => 'Sync',
                    'description' => 'Sync docs sources and inspect source metadata.',
                    'commands' => [
                        [
                            'path' => 'sync',
                            'summary' => 'Sync all enabled sources or one source.',
                            'description' => 'Sync enabled documentation sources into the Docs Manager database. Use --plugin to sync only one source.',
                            'usageOptions' => '[--plugin=<plugin>]',
                            'options' => [
                                [
                                    'name' => '--plugin',
                                    'description' => 'Sync only one plugin source instead of all enabled sources.',
                                ],
                            ],
                            'examples' => [
                                'docs-manager/sync',
                                'docs-manager/sync --plugin=search-manager',
                            ],
                        ],
                        [
                            'path' => 'sync/plugin',
                            'summary' => 'Sync one source by handle or folder name.',
                            'description' => 'Sync a specific source after editing markdown locally. Plugin input accepts either the source DB handle or a local plugin folder name.',
                            'arguments' => '<plugin>',
                            'examples' => [
                                'docs-manager/sync/plugin search-manager',
                                'docs-manager/sync/plugin base',
                            ],
                            'notes' => [
                                'If no source is registered yet, a local source is created automatically on first sync when the handle resolves to a plugin/module directory (with a composer.json) under the configured Local Plugin Base Path. GitHub sources must be added in the CP.',
                            ],
                        ],
                        [
                            'path' => 'sync/version',
                            'summary' => 'Check detected version metadata for a source.',
                            'description' => 'Show the detected version and which source won, such as GitHub API, Packagist, git tags, or composer.json.',
                            'arguments' => '<plugin>',
                            'examples' => [
                                'docs-manager/sync/version search-manager',
                                'docs-manager/sync/version base',
                            ],
                        ],
                        [
                            'path' => 'sync/test-parser',
                            'summary' => 'Test markdown parsing for one file.',
                            'description' => 'Parse one markdown file and print frontmatter, headings, and an HTML preview. Useful before a full source sync.',
                            'arguments' => '<filePath>',
                            'examples' => [
                                'docs-manager/sync/test-parser plugins/search-manager/docs/feature-tour/overview.md',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'templates',
                    'label' => 'Templates',
                    'description' => 'Install starter frontend templates.',
                    'commands' => [
                        [
                            'path' => 'templates/install',
                            'summary' => 'Install starter documentation templates.',
                            'description' => 'Copy starter frontend templates into the project templates folder and optionally add frontend routes.',
                            'examples' => [
                                'docs-manager/templates/install',
                            ],
                            'notes' => [
                                'This command prompts before overwriting existing files and before adding routes.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
