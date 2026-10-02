<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Throwable;
use TomaszBoloz\LaravelUpdater\Authorization;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;
use UnitEnum;

/** Admin page: application version, installed packages with updates (all or one), private packages, live log. */
final class UpdaterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $slug = 'updater';

    protected string $view = 'updater::filament.updater-page';

    /** Filament re-checks this on mount and on every Livewire request, so actions are covered too. */
    public static function canAccess(): bool
    {
        return Authorization::allows();
    }

    public static function getNavigationLabel(): string
    {
        return __('updater::updater.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::plugin()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return self::plugin()?->getNavigationSort();
    }

    /** Number of available updates from the last (manual or scheduled) check; no network calls. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = self::canAccess() ? app(UpdateChecker::class)->availableCount() : 0;
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public function getTitle(): string
    {
        return __('updater::updater.title');
    }

    public function updateApplicationAction(): Action
    {
        return UpdateActions::application();
    }

    public function updatePackageAction(): Action
    {
        return UpdateActions::packages('updatePackage', all: false);
    }

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            UpdateActions::check(),
            UpdateActions::packages('updateAllPackages', all: true),
            ActionGroup::make([SettingsAction::make(), PrivatePackagesAction::make()])
                ->label(__('updater::updater.settings.title'))
                ->icon('heroicon-o-cog-6-tooth')
                ->button()
                ->color('gray'),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $lookup = $this->lookup();
        $status = app(Status::class);
        $inventory = app(PackageInventory::class);

        return [
            ...$lookup,
            'current' => app(Updater::class)->currentVersion(),
            'status' => $status->get(),
            'busy' => $status->isBusy() || app(UpdateChecker::class)->isChecking(),
            'packages' => $inventory->all(),
            'check' => $inventory->lastCheck(),
            // Release notes are untrusted: raw HTML is escaped and unsafe links are dropped.
            'notes' => $lookup['release'] === null ? null
                : Str::markdown($lookup['release']->notes, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
        ];
    }

    private static function plugin(): ?UpdaterPlugin
    {
        return Filament::getCurrentPanel()?->hasPlugin(UpdaterPlugin::ID) === true ? UpdaterPlugin::get() : null;
    }

    /** @return array{release: Release|null, error: string|null} */
    private function lookup(): array
    {
        try {
            return ['release' => app(Updater::class)->available(), 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['release' => null, 'error' => $exception->getMessage()];
        }
    }
}
