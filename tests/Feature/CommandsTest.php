<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use TomaszBoloz\LaravelUpdater\Jobs\RunTask;
use TomaszBoloz\LaravelUpdater\Sources\Source;
use TomaszBoloz\LaravelUpdater\State\RunLock;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\FakeSource;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;

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
        $lock = new RunLock(storage_path('app/updater/run.lock'));
        $lock->acquire();

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

    public function test_background_runs_start_a_detached_process(): void
    {
        Process::fake();

        $this->artisan('updater:run --background')->expectsOutputToContain('started in the background')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => is_string($process->command)
            && str_starts_with($process->command, "nohup 'php' 'artisan' 'updater:work' 'application'")
            && str_ends_with($process->command, ' 2>&1 &'));
        $this->assertSame(Status::QUEUED, $this->app->make(Status::class)->get()['state']);
    }

    public function test_the_queue_runner_dispatches_a_job_with_the_updater_queue(): void
    {
        Queue::fake();
        Config::set('updater.runner', 'queue');
        Config::set('updater.queue', ['connection' => 'redis', 'name' => 'updates', 'timeout' => 1800]);

        $this->artisan('updater:packages acme/plugin --background')->assertSuccessful();

        Queue::assertPushed(RunTask::class, fn (RunTask $job): bool => $job->task === 'packages' && $job->package === 'acme/plugin'
            && $job->connection === 'redis' && $job->queue === 'updates' && $job->timeout === 1800 && $job->tries === 1);
    }

    public function test_the_work_command_runs_the_task_and_refreshes_the_check_in_a_new_process(): void
    {
        Process::fake();

        $this->artisan('updater:work application')->assertSuccessful();

        $this->assertSame(Status::SUCCEEDED, $this->app->make(Status::class)->get()['state']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['php', 'artisan', 'updater:check']);
        $this->artisan('updater:work --help')->assertSuccessful();
        $this->artisan('updater:work rm')->assertExitCode(2);
    }
}
