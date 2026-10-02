<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use TomaszBoloz\LaravelUpdater\Events\UpdateFailed;
use TomaszBoloz\LaravelUpdater\Events\UpdateSucceeded;
use TomaszBoloz\LaravelUpdater\Sources\Source;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\FakeSource;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\Updater;
use TomaszBoloz\LaravelUpdater\UpdaterException;
use TomaszBoloz\LaravelUpdater\VersionStore;

final class UpdaterTest extends TestCase
{
    private FakeSource $source;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Http::fake(['*' => Http::response($this->release())]);
        Config::set('updater.steps', [['@composer', 'install'], ['@php', 'artisan', 'migrate', '--force'], ['@npm', 'run', 'build']]);
        Config::set('updater.recovery_steps', [['@php', 'artisan', 'optimize:clear']]);
        Config::set('updater.maintenance.secret', 'let-me-in');
        $this->useSource(new FakeSource);
    }

    public function test_it_installs_the_release_and_runs_every_step_inside_maintenance_mode(): void
    {
        $this->fakeProcesses();

        $release = $this->app->make(Updater::class)->update();

        $this->assertSame('1.2.0', $release?->version());
        $this->assertSame(['snapshot', 'apply v1.2.0'], $this->source->calls);
        $this->assertSame([
            'php artisan down --retry=60 --secret=let-me-in',
            'composer install',
            'php artisan migrate --force',
            'npm run build',
            'php artisan up',
        ], $this->ran);
        $this->assertSame('1.2.0', $this->app->make(VersionStore::class)->current());

        $status = $this->app->make(Status::class)->get();
        $this->assertSame(Status::SUCCEEDED, $status['state']);
        $this->assertContains('$ php artisan down --retry=60 --secret=********', $status['log'], 'Secrets never reach the log.');
        Event::assertDispatched(UpdateSucceeded::class);
    }

    public function test_a_failed_step_rolls_back_runs_recovery_and_brings_the_site_up(): void
    {
        $this->fakeProcesses(fn (string $command) => str_contains($command, 'migrate')
            ? Process::result(errorOutput: 'SQLSTATE: table exists', exitCode: 1)
            : Process::result());

        try {
            $this->app->make(Updater::class)->update();
            $this->fail('The failing migration must abort the update.');
        } catch (UpdaterException $exception) {
            $this->assertStringContainsString('php artisan migrate --force', $exception->getMessage());
        }

        $this->assertSame(['snapshot', 'apply v1.2.0', 'restore abc'], $this->source->calls);
        $this->assertSame([
            'php artisan down --retry=60 --secret=let-me-in',
            'composer install',
            'php artisan migrate --force',
            'php artisan optimize:clear',
            'php artisan up',
        ], $this->ran);
        $this->assertSame('1.0.0', $this->app->make(VersionStore::class)->current(), 'The version is kept after a failure.');
        $this->assertSame(Status::FAILED, $this->app->make(Status::class)->get()['state']);
        Event::assertDispatched(UpdateFailed::class);
    }

    public function test_a_failed_rollback_is_logged_and_the_original_error_is_kept(): void
    {
        $this->useSource(new FakeSource(failApply: true, failRestore: true));
        $this->fakeProcesses();

        try {
            $this->app->make(Updater::class)->update();
            $this->fail('The failing download must abort the update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('download failed', $exception->getMessage());
        }

        $status = $this->app->make(Status::class)->get();
        $this->assertContains('! restore failed', $status['log']);
        $this->assertSame([Status::FAILED, 'download failed'], [$status['state'], $status['message']]);
        $this->assertContains('php artisan up', $this->ran, 'The site comes back up even when the rollback fails.');
    }

    public function test_sources_without_snapshots_skip_rollback(): void
    {
        $this->useSource(new FakeSource(snapshot: null, failApply: true));
        $this->fakeProcesses();

        $this->expectException(RuntimeException::class);

        try {
            $this->app->make(Updater::class)->update();
        } finally {
            $this->assertSame(['snapshot', 'apply v1.2.0'], $this->source->calls);
            $this->assertSame(['php artisan down --retry=60 --secret=let-me-in', 'php artisan up'], $this->ran);
        }
    }

    public function test_nothing_runs_when_the_application_is_up_to_date(): void
    {
        Config::set('updater.current_version', 'v1.2.0');
        $this->fakeProcesses();

        $this->assertNull($this->app->make(Updater::class)->update());
        $this->assertSame([], $this->source->calls);
        Process::assertNothingRan();
    }

    public function test_maintenance_mode_can_be_disabled(): void
    {
        Config::set('updater.maintenance.enabled', false);
        $this->fakeProcesses();

        $this->app->make(Updater::class)->update();

        $this->assertNotContains('php artisan up', $this->ran);
    }

    private function useSource(FakeSource $source): void
    {
        $this->source = $source;
        $this->app->instance(Source::class, $source);
    }

    /** @param (\Closure(string): mixed)|null $result */
    private function fakeProcesses(?\Closure $result = null): void
    {
        Process::fake(function (PendingProcess $process) use ($result): mixed {
            $this->ran[] = $command = implode(' ', (array) $process->command);

            return $result === null ? Process::result() : $result($command);
        });
    }
}
