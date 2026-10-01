<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\VersionStore;

final class StatusTest extends TestCase
{
    public function test_it_tracks_a_run_and_keeps_only_the_log_tail(): void
    {
        $status = $this->app->make(Status::class);
        $this->assertSame(Status::IDLE, $status->get()['state']);

        $status->start('1.2.0');
        $status->step('composer install');
        $status->append(implode("\n", array_map(static fn (int $line): string => "line {$line}", range(1, 400))));

        $current = $status->get();
        $this->assertSame(Status::RUNNING, $current['state']);
        $this->assertSame('composer install', $current['step']);
        $this->assertCount(300, $current['log']);
        $this->assertSame('line 400', end($current['log']));
        $this->assertTrue($status->isBusy());

        $status->finish(Status::SUCCEEDED);
        $this->assertFalse($status->isBusy());
        $this->assertNull($status->get()['step']);
    }

    public function test_a_run_abandoned_by_a_killed_worker_stops_blocking(): void
    {
        $this->travelTo(now()->subHours(3), fn () => $this->app->make(Status::class)->start('1.2.0'));

        $this->assertFalse($this->app->make(Status::class)->isBusy());
    }

    public function test_corrupted_cache_entries_fall_back_to_idle(): void
    {
        Cache::forever('updater:status', ['state' => ['x'], 'log' => 'nope']);

        $this->assertSame(Status::IDLE, $this->app->make(Status::class)->get()['state']);
        $this->assertSame([], $this->app->make(Status::class)->get()['log']);
    }

    public function test_the_installed_version_falls_back_to_config_until_an_update_is_remembered(): void
    {
        Config::set('updater.current_version', 'v1.0.0');
        $versions = $this->app->make(VersionStore::class);
        $this->assertSame('1.0.0', $versions->current());

        $versions->remember(new Release('v1.3.0', 'name'));
        $this->assertSame('1.3.0', $versions->current());

        File::put(storage_path('app/updater/version'), 'garbage');
        $this->assertSame('1.0.0', $versions->current(), 'An invalid stored value is ignored.');
    }
}
