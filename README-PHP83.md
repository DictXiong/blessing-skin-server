<!--
This file was generated entirely by AI.
It has not been reviewed line by line by a human.
-->

# PHP 8.3 compatibility fork

This unofficial fork adapts Blessing Skin Server **6.0.2** to PHP **8.3.36**.
Upstream commit: `b737a65f5f5fce1f897f8603db74fd970437b351`.
It is a compatibility prerelease. It does not imply endorsement by Blessing Skin's maintainers.

## Changes

- Laravel 12.72.0, Passport 12.4.3, Carbon 3.15.1 and Twig 3.30.0, with the related routing, authorization, translation and view integration changes.
- JWT 4.3.0 preserves the mutable Builder API used by unmodified yggdrasil-api 5.2.1. Public fixed plugin inputs cover authentication, validation, refresh, profile selection, join/hasJoined, signed profile properties and invalidation.
- An application search parser replaces laravel-search-string and its abandoned HOA dependencies. Existing model/controller calls, field filters, aliases, boolean/date expressions, sorting, projections and paging remain available. LIKE literals work with SQLite and MariaDB.
- Two local MIT-licensed Blessing packages retain their upstream 1.4.0 implementation; only dependency metadata is extended for Illuminate 12.

Search parsing adapts [mk990's implementation](https://github.com/mk990/blessing-skin-server/tree/55597dbd3ff244db893a05c141479ded96dfca32/app/Services/Search), with additional compatibility fixes. JWT compatibility follows [Fuu's test and dependency constraint](https://github.com/FuuuuWaaaaaaa/blessing-skin-server/blob/97978be78135a7c09b6e57652550dd46e2bbfa7f/tests/CompatibilityTest/LcobucciJwtCompatibilityTest.php). Original licenses and authorship remain intact. These fork changes were prepared with AI assistance; they have not been reviewed line by line by a human.

## Validation

Local PHP 8.3.36 verification: **240 tests, 1910 assertions, zero failures/errors/skips**.
Additional isolated MariaDB 11.4.12 checks used a new schema and strict SQL mode for 14 search checks.
The dependency audit at release preparation reported zero advisories and zero abandoned packages; advisories can change.
The production build installs without development dependencies, checks platform requirements, runs search/JWT checks against a disposable SQLite database, and checks three HTTP pages plus CSS.

Existing production database upgrades/rollback, other third-party plugins, actual Minecraft clients, browser JavaScript, external OAuth providers and mail delivery have not been validated. The MariaDB checks do not validate an old production schema.

## Build

Use PHP **8.3.36**, Composer 2, Python 3 and the PHP extensions required by composer.json, plus pdo_sqlite for the build checks.
The frontend is reused from the fixed official 6.0.2 distribution; no Node frontend rebuild is claimed.

```sh
curl -fL https://github.com/bs-community/blessing-skin-server/releases/download/6.0.2/blessing-skin-server-6.0.2.zip -o /tmp/blessing-skin-6.0.2.zip
python3 tools/build-php83.py --frontend-archive /tmp/blessing-skin-6.0.2.zip
```

The script enforces frontend SHA256
`6090d10cb2d751191ae4f343f366fbc51dad8844aace63789e1b47e838121f2a`
and restores only the 69 fixed frontend files. Outputs are under `dist/php83/`.
The runtime tarball contains production dependencies and frontend assets, with no `.env`, database, generated application key, test keys or test suite. Configure a new installation or back up and test an existing installation separately.

To run the regression suite from a fresh clone:

```sh
python3 tools/build-php83.py --frontend-archive /tmp/blessing-skin-6.0.2.zip --prepare-assets-only
composer install --no-plugins --no-scripts --prefer-dist --no-interaction
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/textures resources/views/overrides
APP_ENV=testing php artisan package:discover
APP_ENV=testing php artisan passport:keys --length=2048
php vendor/bin/phpunit
```

The tracked `.env.testing` contains upstream's public synthetic test configuration. It is not included in the runtime distribution.
See [search behavior and attribution](app/Services/Search/SOURCE.md) and [fixed plugin fixture provenance](tests/fixtures/yggdrasil-api/FIXTURE.md).

## 中文说明

这是基于官方 6.0.2 的非官方 PHP 8.3.36 兼容分支，发布为预发布版本。
修复依赖升级后的 OAuth、翻译和视图集成，兼容旧 Yggdrasil 插件，并替换停维护的搜索依赖。
本地测试和新库验证不能代替实际旧库迁移、其他插件及真实客户端验证。运行包不含本机状态或凭据；本项目没有执行服务器部署。
