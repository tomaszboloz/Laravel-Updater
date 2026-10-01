<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\AdminPanelProvider;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\User;

/** Boots Filament with a panel that registers the updater plugin. */
abstract class FilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ...$this->filamentProviders(), AdminPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake(['api.github.com/repos/acme/shop/releases/latest' => Http::response($this->release(['body' => "<script>alert(1)</script>\n\n**Faster** checkout"]))]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']));
    }

    /** @return list<class-string> */
    private function filamentProviders(): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            LivewireServiceProvider::class,
            FilamentServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
        ];
    }
}
