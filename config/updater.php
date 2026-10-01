<?php

declare(strict_types=1);

// Repository, token, strategy, installed version and maintenance options can be edited in the
// Filament panel (Updates → Settings). Saved panel values take precedence over this file and .env.
return [

    /*
    | GitHub repository that publishes your application's releases, as "owner/repository".
    | Releases must be tagged with semantic versions, e.g. "v1.4.0" or "1.4.0".
    */
    'repository' => env('UPDATER_REPOSITORY'),

    /*
    | Token for private repositories (fine-grained PAT with "Contents: read-only").
    | Leave empty for public repositories. It is never written to logs or the status log.
    */
    'token' => env('UPDATER_GITHUB_TOKEN'),

    'api_url' => env('UPDATER_API_URL', 'https://api.github.com'),

    /*
    | "git"     - the app is a git checkout: fetch the release tag and check it out (rollback on failure).
    | "archive" - download the release zipball and copy it over the app (hosts without git, no rollback).
    */
    'strategy' => env('UPDATER_STRATEGY', 'git'),

    // Version installed before the first update; later the updater remembers it in storage.
    'current_version' => env('UPDATER_CURRENT_VERSION', '0.0.0'),

    // Gate ability required to see the admin page and start updates. Define it in your app.
    'ability' => 'updater.manage',

    'check_cache_minutes' => 10,

    /*
    | Automatic monitoring of the application and every package (cron expression, null = off).
    | Requires Laravel's scheduler: "* * * * * php artisan schedule:run". Found updates dispatch UpdatesAvailable.
    */
    'schedule' => env('UPDATER_SCHEDULE', '0 */6 * * *'),

    // Seconds allowed for a single step (composer, npm build...).
    'timeout' => 900,

    'maintenance' => [
        'enabled' => true,
        'retry' => 60,
        // Optional bypass secret: visit /{secret} to use the site while it is down.
        'secret' => env('UPDATER_MAINTENANCE_SECRET'),
    ],

    // "@php", "@composer", "@npm" and "@git" in steps resolve to these binaries.
    'binaries' => [
        'php' => env('UPDATER_PHP_BINARY'), // null = the PHP CLI running Laravel
        'composer' => env('UPDATER_COMPOSER_BINARY', 'composer'),
        'npm' => env('UPDATER_NPM_BINARY', 'npm'),
        'git' => env('UPDATER_GIT_BINARY', 'git'),
    ],

    // Extra environment for every step; queue workers often lack HOME/COMPOSER_HOME.
    'environment' => [
        'HOME' => env('UPDATER_HOME'),
        'COMPOSER_HOME' => env('UPDATER_COMPOSER_HOME'),
    ],

    // Run in order after the new code is in place. Remove what your app does not use.
    'steps' => [
        ['@composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
        ['@php', 'artisan', 'migrate', '--force'],
        ['@npm', 'ci', '--no-audit', '--no-fund'],
        ['@npm', 'run', 'build'],
        ['@php', 'artisan', 'optimize:clear'],
        ['@php', 'artisan', 'optimize'],
        ['@php', 'artisan', 'queue:restart'],
    ],

    // Run after a failed update has been rolled back to the previous code ("git" strategy).
    'recovery_steps' => [
        ['@composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
        ['@php', 'artisan', 'optimize:clear'],
    ],

    /*
    | Package updates from the panel: "{packages}" becomes the package name, or disappears when updating all.
    | Private packages are added in the panel (Updates → Private packages) with their own tokens.
    */
    'package_steps' => [
        ['@composer', 'update', '{packages}', '--with-dependencies', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
        ['@php', 'artisan', 'migrate', '--force'],
        ['@php', 'artisan', 'optimize:clear'],
        ['@php', 'artisan', 'optimize'],
        ['@php', 'artisan', 'queue:restart'],
    ],

    // Run after a failed package update, once composer.lock has been restored.
    'package_recovery_steps' => [
        ['@composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
        ['@php', 'artisan', 'optimize:clear'],
    ],

    // "git" strategy: tracked files whose local changes do not block an application update (changed by package updates).
    'git_ignored_changes' => ['composer.lock'],

    // "archive" strategy: paths (relative to the app root) that are never overwritten.
    'preserve' => ['.env', '.git', 'storage', 'vendor', 'node_modules', 'bootstrap/cache', 'public/storage'],

    /*
    | How tasks started from the panel run, always outside the web request:
    | "process" - a detached "php artisan updater:work" process (no queue worker needed; not on Windows),
    | "queue"   - a queued RunTask job on the connection below (needs a running worker).
    */
    'runner' => env('UPDATER_RUNNER', 'process'),

    // Prefix of the progress endpoint polled by the admin page ("/updater/status").
    'route_prefix' => 'updater',

    'queue' => [
        'connection' => env('UPDATER_QUEUE_CONNECTION'),
        'name' => env('UPDATER_QUEUE'),
        'timeout' => 3600,
    ],

    // Cache for GitHub API answers. Progress, lock and check results live in files in storage/app/updater.
    'cache_store' => env('UPDATER_CACHE_STORE'),
];
