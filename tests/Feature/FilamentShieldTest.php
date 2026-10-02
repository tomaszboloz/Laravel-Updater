<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use TomaszBoloz\LaravelUpdater\Authorization;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPage;
use TomaszBoloz\LaravelUpdater\Tests\FilamentTestCase;

final class FilamentShieldTest extends FilamentTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FilamentShieldServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('updater.manage', static fn (): bool => true);
    }

    public function test_the_shield_page_permission_replaces_the_gate(): void
    {
        $this->assertSame('View:UpdaterPage', Authorization::shieldPermission());
        $this->assertArrayHasKey(UpdaterPage::class, FilamentShield::getPages() ?? [], 'Listed in the role editor.');
        $this->assertFalse(UpdaterPage::canAccess(), 'The "updater.manage" gate alone no longer opens the page.');
        $this->getJson(route('updater.status'))->assertForbidden();

        Gate::define('View:UpdaterPage', static fn (): bool => true);

        $this->assertTrue(UpdaterPage::canAccess());
        Livewire::test(UpdaterPage::class)->assertOk();
        $this->getJson(route('updater.status'))->assertOk();
    }

    public function test_the_gate_decides_when_shield_excludes_the_page_or_it_is_turned_off(): void
    {
        Config::set('filament-shield.pages.exclude', [UpdaterPage::class]);

        $this->assertNull(Authorization::shieldPermission());
        $this->assertTrue(UpdaterPage::canAccess());

        Config::set('filament-shield.pages.exclude', []);
        Config::set('updater.shield', false);

        $this->assertNull(Authorization::shieldPermission());
        $this->assertTrue(UpdaterPage::canAccess());
    }
}
