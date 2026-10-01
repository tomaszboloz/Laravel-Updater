<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;
use TomaszBoloz\LaravelUpdater\ProcessEnvironment;
use TomaszBoloz\LaravelUpdater\UpdaterServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const string TOKEN = 'github_pat_secret_token_123';

    protected function setUp(): void
    {
        parent::setUp();

        ProcessEnvironment::$enabled = false;

        // The installed version lives in storage, which testbench shares between tests.
        (new Filesystem)->deleteDirectory(storage_path('app/updater'));
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [UpdaterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), static function (Repository $config): void {
            $config->set('cache.default', 'array');
            $config->set('updater.repository', 'acme/shop');
            $config->set('updater.current_version', '1.0.0');
            $config->set('updater.binaries.php', 'php');
        });
    }

    /** @param array<string, mixed> $overrides */
    protected function release(array $overrides = []): array
    {
        return [
            'tag_name' => 'v1.2.0',
            'name' => 'Spring release',
            'body' => "## Changes\n- faster checkout",
            'html_url' => 'https://github.com/acme/shop/releases/tag/v1.2.0',
            'published_at' => '2026-09-30T10:00:00Z',
            ...$overrides,
        ];
    }
}
