<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use TomaszBoloz\LaravelUpdater\Background;
use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\InstalledApp;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\User;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;

final class BackgroundTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function usePanelGuard($app): void
    {
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('updater.route_middleware', ['web', 'auth:admin']);
    }

    public function test_tasks_and_package_names_are_validated_before_anything_starts(): void
    {
        Process::fake();
        $background = $this->app->make(Background::class);

        foreach ([['rm -rf', null], ['packages', '--no-plugins'], ['packages', 'acme/plugin; reboot']] as [$task, $package]) {
            try {
                $background->start($task, $package);
                $this->fail('An invalid task must be rejected.');
            } catch (UpdaterException) {
            }
        }

        Process::assertNothingRan();
    }

    public function test_the_admin_who_starts_an_update_gets_the_maintenance_bypass_cookie(): void
    {
        Process::fake();
        Route::middleware('web')->post('/start-update', function (Background $background) {
            $background->start('packages', 'acme/plugin');

            return 'ok';
        });

        $response = $this->post('/start-update')->assertOk();

        $this->assertNotNull($response->getCookie('laravel_maintenance', false), 'Not encrypted: Laravel reads it before decryption.');
        Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, "'updater:work' 'packages' '--package=acme/plugin'"));
    }

    public function test_the_status_endpoint_is_protected_by_the_gate(): void
    {
        $user = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $this->app->make(Status::class)->start('application');
        $this->app->make(Status::class)->step('composer install');

        $this->getJson(route('updater.status'))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('updater.status'))->assertForbidden();

        Gate::define('updater.manage', static fn (): bool => true);

        $this->actingAs($user)->getJson(route('updater.status'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['state' => 'running', 'target' => 'application', 'step' => 'composer install', 'log' => ['$ composer install']]);
    }

    #[DefineEnvironment('usePanelGuard')]
    public function test_the_status_endpoint_authenticates_with_the_configured_guard(): void
    {
        $user = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        Gate::define('updater.manage', static fn (User $admin): bool => $admin === $user);

        $this->actingAs($user)->getJson(route('updater.status'))->assertUnauthorized();
        $this->actingAs($user, 'admin')->getJson(route('updater.status'))->assertOk();
    }

    public function test_development_installs_skip_commands_that_cache_configuration(): void
    {
        $installed = new InstalledApp([], [], []);
        file_put_contents($installed->path.'/vendor/composer/installed.json', json_encode(['packages' => [], 'dev' => true]));
        $this->app->setBasePath($installed->path);
        Process::fake();
        $output = [];

        try {
            $runner = $this->app->make(CommandRunner::class);
            $runner->run(['@php', 'artisan', 'optimize'], onOutput: function (string $line) use (&$output): void {
                $output[] = $line;
            });
            $runner->run(['@php', 'artisan', 'optimize:clear']);
        } finally {
            $installed->delete();
        }

        $this->assertSame(['skipped on a development install'], $output);
        Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === ['php', 'artisan', 'optimize:clear'], 1);
        Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command === ['php', 'artisan', 'optimize']);
    }
}
