<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPage;
use TomaszBoloz\LaravelUpdater\RunUpdate;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\AdminPanelProvider;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\User;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;

final class FilamentPageTest extends TestCase
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
        Http::fake(['*' => Http::response($this->release(['body' => "<script>alert(1)</script>\n\n**Faster** checkout"]))]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']));
    }

    public function test_the_page_is_denied_unless_the_gate_allows_it(): void
    {
        $this->assertFalse(UpdaterPage::canAccess());

        Livewire::test(UpdaterPage::class)->assertForbidden();
    }

    public function test_it_shows_versions_and_escapes_release_notes(): void
    {
        Gate::define('updater.manage', static fn (): bool => true);

        Livewire::test(UpdaterPage::class)
            ->assertOk()
            ->assertSee('1.0.0')
            ->assertSee('1.2.0')
            ->assertSeeHtml('<strong>Faster</strong>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertActionVisible('update');

        $this->assertSame('System', UpdaterPage::getNavigationGroup());
        $this->assertSame(5, UpdaterPage::getNavigationSort());
    }

    public function test_the_update_action_queues_the_job(): void
    {
        Gate::define('updater.manage', static fn (): bool => true);
        Queue::fake();

        Livewire::test(UpdaterPage::class)->callAction('update')->assertHasNoActionErrors();

        Queue::assertPushed(RunUpdate::class);
        $this->assertSame(Status::QUEUED, $this->app->make(Status::class)->get()['state']);
    }

    public function test_the_update_action_is_hidden_while_an_update_runs(): void
    {
        Gate::define('updater.manage', static fn (): bool => true);
        $this->app->make(Status::class)->start('1.2.0');

        Livewire::test(UpdaterPage::class)->assertActionHidden('update');
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
