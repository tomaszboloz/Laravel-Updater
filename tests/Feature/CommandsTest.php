<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use TomaszBoloz\LaravelUpdater\RunUpdate;
use TomaszBoloz\LaravelUpdater\Sources\Source;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\FakeSource;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;

final class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response($this->release())]);
        $this->app->instance(Source::class, new FakeSource);
    }

    public function test_check_reports_an_available_update(): void
    {
        Process::fake(['*outdated*' => Process::result('{"installed": []}')]);

        $this->artisan('updater:check')
            ->expectsOutputToContain('1.0.0 → 1.2.0')
            ->assertSuccessful();
    }

    public function test_check_fails_when_the_repository_is_not_configured(): void
    {
        Config::set('updater.repository', null);

        Process::fake(['*outdated*' => Process::result('{"installed": []}')]);

        $this->artisan('updater:check')->expectsOutputToContain('not configured')->assertFailed();
    }

    public function test_run_installs_the_update_synchronously(): void
    {
        Process::fake();

        $this->artisan('updater:run')->expectsOutputToContain('Updated to 1.2.0.')->assertSuccessful();
    }

    public function test_run_refuses_to_start_while_another_update_holds_the_lock(): void
    {
        Process::fake();
        $lock = Cache::lock('updater:lock', 60);
        $lock->get();

        $this->artisan('updater:run')->expectsOutputToContain('already running')->assertFailed();

        $lock->release();
        Process::assertNothingRan();
    }

    public function test_run_asks_for_confirmation_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        Process::fake();

        $this->artisan('updater:run')->expectsConfirmation('Are you sure you want to run this command?', 'no')->assertFailed();
        Process::assertNothingRan();
    }

    public function test_run_can_queue_the_update(): void
    {
        Queue::fake();
        Config::set('updater.queue', ['connection' => 'redis', 'name' => 'updates', 'timeout' => 1800]);

        $this->artisan('updater:run --queue')->expectsOutputToContain('Update to 1.2.0 queued.')->assertSuccessful();

        Queue::assertPushed(RunUpdate::class, fn (RunUpdate $job): bool => $job->connection === 'redis'
            && $job->queue === 'updates' && $job->timeout === 1800 && $job->tries === 1);
        $this->assertSame(Status::QUEUED, $this->app->make(Status::class)->get()['state']);
    }

    public function test_the_queued_job_runs_the_updater(): void
    {
        Process::fake();

        (new RunUpdate)->handle($this->app->make(Updater::class), $this->app->make(UpdateChecker::class));

        $this->assertSame(Status::SUCCEEDED, $this->app->make(Status::class)->get()['state']);
    }
}
