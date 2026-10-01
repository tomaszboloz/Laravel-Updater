<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPage;
use TomaszBoloz\LaravelUpdater\RunUpdate;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Tests\FilamentTestCase;

final class FilamentPageTest extends FilamentTestCase
{
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
}
