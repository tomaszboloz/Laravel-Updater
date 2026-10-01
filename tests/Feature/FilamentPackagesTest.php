<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPage;
use TomaszBoloz\LaravelUpdater\Jobs\CheckForUpdates;
use TomaszBoloz\LaravelUpdater\Jobs\UpdatePackages;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\SettingsStore;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\FilamentTestCase;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\InstalledApp;

final class FilamentPackagesTest extends FilamentTestCase
{
    private InstalledApp $installed;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('updater.manage', static fn (): bool => true);
        $this->installed = new InstalledApp(
            ['acme/plugin' => '^1.0', 'acme/theme' => '^2.0', 'laravel/framework' => '^13.0'],
            [],
            ['acme/plugin' => '1.0.0', 'acme/theme' => '2.0.0', 'laravel/framework' => 'v13.1.0'],
        );
        $this->app->setBasePath($this->installed->path);
        $this->app->make(SettingsStore::class)->save(['packages' => [['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token']]]);
        Process::fake(['*outdated*' => Process::result(json_encode(['installed' => [
            ['name' => 'acme/theme', 'latest' => '3.0.0', 'latest-status' => 'update-possible'],
        ]]))]);
        Http::fake([
            'api.github.com/repos/acme/plugin/tags*' => Http::response([['name' => 'v1.1.0']]),
        ]);
        $this->app->make(PackageInventory::class)->check();
    }

    protected function tearDown(): void
    {
        $this->installed->delete();

        parent::tearDown();
    }

    public function test_installed_packages_are_listed_with_their_updates(): void
    {
        Livewire::test(UpdaterPage::class)
            ->assertSee(['acme/plugin', 'private', 'v1.1.0', 'acme/theme', '3.0.0', 'new major version', 'laravel/framework', 'v13.1.0'])
            ->assertSee('2 of 3 packages can be updated.')
            ->assertActionVisible('updateAllPackages');

        $this->assertSame('2', UpdaterPage::getNavigationBadge(), 'The application release and one package.');
    }

    public function test_one_package_or_all_packages_can_be_queued(): void
    {
        Queue::fake();

        Livewire::test(UpdaterPage::class)->callAction('updatePackage', arguments: ['package' => 'acme/plugin']);
        Queue::assertPushed(UpdatePackages::class, fn (UpdatePackages $job): bool => $job->package === 'acme/plugin');
        $this->assertTrue($this->app->make(Status::class)->isBusy(), 'Further updates wait for the queued one.');
        $this->app->make(Status::class)->finish(Status::SUCCEEDED);

        Livewire::test(UpdaterPage::class)->callAction('updateAllPackages');
        Queue::assertPushed(UpdatePackages::class, fn (UpdatePackages $job): bool => $job->package === null);
    }

    public function test_package_names_from_the_browser_must_be_installed_dependencies(): void
    {
        Queue::fake();

        Livewire::test(UpdaterPage::class)->callAction('updatePackage', arguments: ['package' => '--no-plugins']);

        Queue::assertNothingPushed();
    }

    public function test_the_manual_check_runs_in_the_background(): void
    {
        Queue::fake();

        Livewire::test(UpdaterPage::class)->callAction('check')->assertNotified();

        Queue::assertPushed(CheckForUpdates::class);
    }

    public function test_private_packages_are_managed_in_the_panel_and_an_empty_token_keeps_the_saved_one(): void
    {
        $store = $this->app->make(SettingsStore::class);

        Livewire::test(UpdaterPage::class)
            ->mountAction('privatePackages')
            ->assertActionDataSet(fn (array $data): bool => $data['packages'] !== [] && collect($data['packages'])->every(fn ($item): bool => $item['token'] === null))
            ->setActionData(['packages' => [
                ['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => ''],
                ['name' => 'acme/theme', 'repository' => 'acme/theme-repo', 'token' => 'theme-token'],
            ]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([
            ['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token'],
            ['name' => 'acme/theme', 'repository' => 'acme/theme-repo', 'token' => 'theme-token'],
        ], $store->packages());
    }
}
