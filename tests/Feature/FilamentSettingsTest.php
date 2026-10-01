<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPage;
use TomaszBoloz\LaravelUpdater\SettingsStore;
use TomaszBoloz\LaravelUpdater\Tests\FilamentTestCase;
use TomaszBoloz\LaravelUpdater\VersionStore;

final class FilamentSettingsTest extends FilamentTestCase
{
    public function test_settings_are_saved_from_the_panel_and_an_empty_token_keeps_the_stored_one(): void
    {
        Gate::define('updater.manage', static fn (): bool => true);
        $store = $this->app->make(SettingsStore::class);
        $store->save(['token' => self::TOKEN]);

        Livewire::test(UpdaterPage::class)
            ->callAction('settings', data: [
                'repository' => 'acme/panel', 'token' => '', 'strategy' => 'archive', 'current_version' => 'v1.1.0',
                'maintenance_enabled' => false, 'maintenance_retry' => 30, 'maintenance_secret' => 'bypass-123',
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals([
            'repository' => 'acme/panel', 'token' => self::TOKEN, 'strategy' => 'archive',
            'maintenance_enabled' => false, 'maintenance_retry' => 30, 'maintenance_secret' => 'bypass-123',
        ], $store->all());
        $this->assertSame('1.1.0', $this->app->make(VersionStore::class)->current());

        Livewire::test(UpdaterPage::class)
            ->callAction('settings', data: ['repository' => 'not a repo', 'strategy' => 'git', 'current_version' => '1.1.0'])
            ->assertHasActionErrors(['repository' => 'regex']);

        Livewire::test(UpdaterPage::class)
            ->callAction('settings', data: ['repository' => 'acme/panel', 'strategy' => 'git', 'current_version' => '1.1.0', 'forget_token' => true])
            ->assertHasNoActionErrors();
        $this->assertNull($store->get('token'));
    }
}
