# Changelog

## [5.1.0](https://github.com/LindemannRock/craft-docs-manager/compare/v5.0.0...v5.1.0) (2026-06-07)


### Added

* add automatic sync schedule handling and job cancellation ([675e237](https://github.com/LindemannRock/craft-docs-manager/commit/675e23708b69a38d926f93124433caa34feadc14))
* add filtering and sorting options to pages index action ([0df2759](https://github.com/LindemannRock/craft-docs-manager/commit/0df27593bca8113df94b9f26b127fed6af1251fb))
* add plugin credit inclusion in page and source edit templates ([631ee3a](https://github.com/LindemannRock/craft-docs-manager/commit/631ee3a181a8a14d698edb355a0ee770a53b508a))
* add static analysis script for CI workflow ([5289f4e](https://github.com/LindemannRock/craft-docs-manager/commit/5289f4ea1e69d86f015649f3f134bfc78ec975bd))
* **cli:** add HelpController for cli command assistance ([38d1034](https://github.com/LindemannRock/craft-docs-manager/commit/38d10346eb2ba9e796cd919247f839a5db819b02))
* **controllers:** add filtering and sorting options to sources index action ([3de7e23](https://github.com/LindemannRock/craft-docs-manager/commit/3de7e2322325afcdf365c878b291c444e0565600))
* **controllers:** redirect to posted URL with source after save ([9ada2a5](https://github.com/LindemannRock/craft-docs-manager/commit/9ada2a5208a48122f29a8def04037f6d4d368be8))
* **cp:** replace 'app' translations with 'docs-manager' for consistency ([d5d1d34](https://github.com/LindemannRock/craft-docs-manager/commit/d5d1d34583265bdff565c2fdae1967730549ea81))
* **helpers:** add LocalSourcePathHelper for path resolution ([4ed66a5](https://github.com/LindemannRock/craft-docs-manager/commit/4ed66a58b0f3ac78d5eeaeebc69196bfa52c2459))
* **i18n:** add new source and page status messages in multiple languages ([98d908e](https://github.com/LindemannRock/craft-docs-manager/commit/98d908e5114a6eb7ff476467be561e4a128641bb))
* **i18n:** add new translation keys for various actions across locales ([c33423a](https://github.com/LindemannRock/craft-docs-manager/commit/c33423a6aa95306d24745e39ad9aba1d1139d700))
* **i18n:** bootstrap all 12 languages from scratch (210 keys each) ([5c1b787](https://github.com/LindemannRock/craft-docs-manager/commit/5c1b787830007c1fb42de918b043e965173fed4f))
* increase items per page to 100 and document date/time formatting overrides ([f2acc9a](https://github.com/LindemannRock/craft-docs-manager/commit/f2acc9acaaf6735982b4f053402662f8b224df1d))
* **jobs:** replace next run time calculation with ScheduleHelper ([61a8192](https://github.com/LindemannRock/craft-docs-manager/commit/61a8192b104a3c74d3ef3aa937e567c43e5327b0))
* normalize slug handling in beforeValidate methods across elements ([11bc095](https://github.com/LindemannRock/craft-docs-manager/commit/11bc095725ee92327f23d61c5ef3a193f6eea94e))
* normalize slug handling in page and source edit templates ([5db8511](https://github.com/LindemannRock/craft-docs-manager/commit/5db851112104916bfdb912c99c47d4a5f9ae1141))
* **pages:** add siteId handling in page edit and template variables ([00835c0](https://github.com/LindemannRock/craft-docs-manager/commit/00835c04751186cbbf6116f320f04850a507467a))
* **pages:** enhance page metadata display with status and type indicators ([6bf1992](https://github.com/LindemannRock/craft-docs-manager/commit/6bf19929ee287b0d52d905ce7004767bd04d001b))
* **settings:** add attribute labels for additional settings ([bbcf1d0](https://github.com/LindemannRock/craft-docs-manager/commit/bbcf1d0daccb328dc2863865249d000534abb67b))
* **settings:** add plugin name, log level, and items per page settings ([9463f44](https://github.com/LindemannRock/craft-docs-manager/commit/9463f4443a484fb8a6ea0be03e5a8d45af92ef6d))
* **settings:** add sync schedule options and validation logic ([70d1f05](https://github.com/LindemannRock/craft-docs-manager/commit/70d1f05975834918bcab4479546cce7a6dfebaf7))
* **settings:** handle empty multi-state select values and expand interface settings ([c5de8de](https://github.com/LindemannRock/craft-docs-manager/commit/c5de8de58d0221cbbff2e60d4fa4bc9da37e487a))
* **settings:** handle sync schedule changes on settings save ([1ba8724](https://github.com/LindemannRock/craft-docs-manager/commit/1ba8724b065e82b70611de93695e4af00a92bc45))
* **settings:** increase items per page to 100 and add base plugin overrides ([08879eb](https://github.com/LindemannRock/craft-docs-manager/commit/08879ebb12963726b2f151736ba30f4e8984bc2e))
* **settings:** replace plugin name and log level fields with partials ([02d5613](https://github.com/LindemannRock/craft-docs-manager/commit/02d5613346e54297708ba50d246c8322c646e532))
* **settings:** replace static sync schedule options with dynamic retrieval ([edb3a4f](https://github.com/LindemannRock/craft-docs-manager/commit/edb3a4f28b449a979141e3e6a94f42bfeed81c61))
* **sources:** add save shortcut redirect and retain scroll on sources edit page ([3ca924d](https://github.com/LindemannRock/craft-docs-manager/commit/3ca924df79f83f2c17f2367d8ee2bfc49315c6df))
* **sources:** enhance sources index with sorting and permission checks ([6f58f8e](https://github.com/LindemannRock/craft-docs-manager/commit/6f58f8e5fc987502287b842b152dd340d7b13773))


### Fixed

* correct date formatting for creation and update timestamps ([60dd7cc](https://github.com/LindemannRock/craft-docs-manager/commit/60dd7cc53c44a304a4ea2cf9cd99da1873457c08))
* **i18n:** correct error message for saving settings in multiple languages ([72d6b98](https://github.com/LindemannRock/craft-docs-manager/commit/72d6b98768e66a7c6aa944c44be471b55b62874a))
* **i18n:** correct error messages for page and source not found ([c3b0c15](https://github.com/LindemannRock/craft-docs-manager/commit/c3b0c15bfbcc5ff34fccc6c9dec5eb780fc8065d))
* **i18n:** correct Portuguese translations for logs and system logs ([7acb3b7](https://github.com/LindemannRock/craft-docs-manager/commit/7acb3b7ad0246c1ddacfa1148567136ec54b6dab))
* **i18n:** correct punctuation in Japanese translation strings ([98cf7de](https://github.com/LindemannRock/craft-docs-manager/commit/98cf7ded700f4f2543a5009530929a69fa9088fb))
* **i18n:** correct status translations for enabled and disabled states ([c78ba44](https://github.com/LindemannRock/craft-docs-manager/commit/c78ba44a1843709b2c579b3d3d4205ebc49a97c7))
* **jobs:** replace plugin full name with display name in sync description ([ac98643](https://github.com/LindemannRock/craft-docs-manager/commit/ac98643901ee868e4bec87d5b80ca16cdaec38a7))
* **settings:** correct error message for saving settings ([a584031](https://github.com/LindemannRock/craft-docs-manager/commit/a58403139269a258b24d3b312f35bb738012f6e4))
* update date labels to be more user-friendly in edit pages ([2c83fe5](https://github.com/LindemannRock/craft-docs-manager/commit/2c83fe5690c1af1ae2bcc54e785400d226131b99))

## [5.0.0](https://github.com/LindemannRock/craft-docs-manager/compare/v5.0.0...v5.0.0) - 2026-05-21


### Added

* initial Docs Manager plugin implementation ([29e2171](https://github.com/LindemannRock/craft-docs-manager/commit/29e217125b5ec79078799b48e7cd6a20782aa50f))
