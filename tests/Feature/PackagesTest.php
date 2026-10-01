<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use TomaszBoloz\LaravelUpdater\Events\PackagesUpdated;
use TomaszBoloz\LaravelUpdater\Events\PackagesUpdateFailed;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\Packages\PackageUpdater;
use TomaszBoloz\LaravelUpdater\SettingsStore;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\InstalledApp;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;

final class PackagesTest extends TestCase
{
    private InstalledApp $installed;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
        $this->app['config']->set('updater.maintenance.enabled', false);
        $this->installed = new InstalledApp(
            ['php' => '^8.3', 'laravel/framework' => '^13.0', 'acme/plugin' => '^1.0', 'acme/theme' => '^2.0'],
            ['phpunit/phpunit' => '^13.0'],
            ['laravel/framework' => 'v13.1.0', 'acme/plugin' => 'v1.2.0', 'acme/theme' => '2.0.0', 'phpunit/phpunit' => '13.0.0', 'other/transitive' => '1.0.0'],
        );
        $this->app->setBasePath($this->installed->path);
        $this->app->make(SettingsStore::class)->save(['packages' => [['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token']]]);
    }

    protected function tearDown(): void
    {
        $this->installed->delete();

        parent::tearDown();
    }

    public function test_it_lists_direct_dependencies_and_finds_updates_on_composer_and_private_tags(): void
    {
        Process::fake(['*outdated*' => Process::result(json_encode(['installed' => [
            ['name' => 'laravel/framework', 'version' => 'v13.1.0', 'latest' => 'v13.4.0', 'latest-status' => 'semver-safe-update'],
            ['name' => 'acme/theme', 'version' => '2.0.0', 'latest' => '3.0.0', 'latest-status' => 'update-possible'],
        ]]))]);
        Http::fake(['api.github.com/repos/acme/plugin/tags*' => Http::response([['name' => 'v1.3.0'], ['name' => 'v1.2.0']])]);

        $check = $this->app->make(PackageInventory::class)->check();
        $packages = collect($this->app->make(PackageInventory::class)->all())->keyBy('name');

        $this->assertNull($check['error']);
        $this->assertSame(['acme/plugin', 'acme/theme', 'laravel/framework', 'phpunit/phpunit'], $packages->keys()->all(), 'Only installed direct dependencies, without php.');
        $this->assertTrue($packages['acme/plugin']->private);
        $this->assertSame('v1.3.0', $packages['acme/plugin']->latest);
        $this->assertTrue($packages['acme/plugin']->canUpdate());
        $this->assertTrue($packages['laravel/framework']->canUpdate());
        $this->assertTrue($packages['acme/theme']->hasUpdate());
        $this->assertFalse($packages['acme/theme']->canUpdate(), 'A new major version needs a new constraint.');
        $this->assertTrue($packages['phpunit/phpunit']->dev);
        $this->assertFalse($packages['phpunit/phpunit']->hasUpdate());
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer plugin-token'));
    }

    public function test_check_errors_are_reported_without_secrets(): void
    {
        Process::fake(['*outdated*' => Process::result(errorOutput: 'auth failed for plugin-token', exitCode: 1)]);
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $error = (string) $this->app->make(PackageInventory::class)->check()['error'];

        $this->assertStringContainsString('auth failed for ********', $error);
        $this->assertStringContainsString('acme/plugin: GitHub rejected', $error);
        $this->assertStringNotContainsString('plugin-token', $error);
    }

    public function test_one_package_is_updated_with_its_credentials_and_post_update_steps(): void
    {
        Event::fake();
        $this->fakeProcesses();

        $this->app->make(PackageUpdater::class)->update('acme/plugin');

        $this->assertSame('composer update acme/plugin --with-dependencies --no-dev --no-interaction --prefer-dist --optimize-autoloader', $this->ran[0]);
        $this->assertContains('php artisan migrate --force', $this->ran);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command[1] === 'update'
            && str_contains((string) $process->environment['COMPOSER_AUTH'], 'plugin-token')
            && $process->environment['GIT_CONFIG_KEY_0'] === 'http.https://github.com/acme/plugin.extraheader');
        Event::assertDispatched(PackagesUpdated::class, fn (PackagesUpdated $event): bool => $event->package === 'acme/plugin');
    }

    public function test_updating_all_packages_omits_the_package_argument(): void
    {
        $this->fakeProcesses();
        $this->app->make(PackageUpdater::class)->update();
        $this->assertStringStartsWith('composer update --with-dependencies', $this->ran[0]);
    }

    public function test_only_direct_dependencies_can_be_updated(): void
    {
        $this->fakeProcesses();

        foreach (['other/transitive', '--no-plugins', 'acme/plugin; rm -rf /'] as $package) {
            try {
                $this->app->make(PackageUpdater::class)->update($package);
                $this->fail("{$package} must be rejected.");
            } catch (UpdaterException $exception) {
                $this->assertStringContainsString('is not a direct dependency', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->ran);
    }

    public function test_a_failed_update_restores_composer_lock(): void
    {
        Event::fake();
        $this->fakeProcesses(fn (string $command) => str_contains($command, 'migrate') ? Process::result(exitCode: 1) : Process::result());
        file_put_contents($this->installed->path.'/composer.lock', '{"changed": true}');
        $original = (string) file_get_contents($this->installed->path.'/composer.lock');

        try {
            $this->app->make(PackageUpdater::class)->update('acme/plugin');
            $this->fail('The failing migration must abort the update.');
        } catch (UpdaterException) {
        }

        $this->assertSame($original, file_get_contents($this->installed->path.'/composer.lock'));
        $this->assertContains('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader', $this->ran);
        Event::assertDispatched(PackagesUpdateFailed::class);
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
