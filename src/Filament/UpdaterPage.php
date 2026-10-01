<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\RunUpdate;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\Updater;
use UnitEnum;

/** Admin page: installed/available version, release notes, update button and live log. */
final class UpdaterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $slug = 'updater';

    protected string $view = 'updater::filament.updater-page';

    /** Filament re-checks this on mount and on every Livewire request, so actions are covered too. */
    public static function canAccess(): bool
    {
        $ability = Config::get('updater.ability', 'updater.manage');

        return is_string($ability) && Gate::allows($ability);
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

    public function getTitle(): string
    {
        return __('updater::updater.title');
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            SettingsAction::make(),
            Action::make('check')
                ->label(__('updater::updater.check'))
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->action(function (): void {
                    $lookup = $this->lookup(fresh: true);

                    Notification::make()
                        ->title($lookup['error'] ?? ($lookup['release'] === null
                            ? __('updater::updater.up_to_date')
                            : __('updater::updater.available', ['version' => $lookup['release']->version()])))
                        ->status($lookup['error'] === null ? 'success' : 'danger')
                        ->send();
                }),
            Action::make('update')
                ->label(__('updater::updater.update'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn (Status $status): bool => $this->lookup()['release'] !== null && ! $status->isBusy())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('updater::updater.confirm', ['version' => $this->lookup()['release']?->version()]))
                ->action(function (Status $status): void {
                    $release = $this->lookup(fresh: true)['release'];

                    if ($release === null || $status->isBusy()) {
                        return;
                    }

                    $status->queue($release->version());
                    RunUpdate::dispatch();

                    Notification::make()->title(__('updater::updater.queued'))->success()->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $lookup = $this->lookup();
        $status = app(Status::class);

        return [
            ...$lookup,
            'current' => app(Updater::class)->currentVersion(),
            'status' => $status->get(),
            'busy' => $status->isBusy(),
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
    private function lookup(bool $fresh = false): array
    {
        try {
            return ['release' => app(Updater::class)->available($fresh), 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['release' => null, 'error' => $exception->getMessage()];
        }
    }
}
