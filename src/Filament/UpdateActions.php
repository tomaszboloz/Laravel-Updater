<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use TomaszBoloz\LaravelUpdater\Jobs\CheckForUpdates;
use TomaszBoloz\LaravelUpdater\Jobs\UpdatePackages;
use TomaszBoloz\LaravelUpdater\Packages\Package;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\RunUpdate;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;

/** Check and update actions of the Updates page. Each one queues a job; the page polls the shared status. */
final class UpdateActions
{
    /** Manual check of the application and every package (the same check also runs on the schedule). */
    public static function check(): Action
    {
        return Action::make('check')
            ->label(__('updater::updater.check'))
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->disabled(fn (UpdateChecker $checker): bool => $checker->isChecking())
            ->action(function (UpdateChecker $checker): void {
                $checker->markChecking();
                CheckForUpdates::dispatch();

                Notification::make()->title(__('updater::updater.checking'))->info()->send();
            });
    }

    public static function application(): Action
    {
        return Action::make('updateApplication')
            ->label(__('updater::updater.update'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->visible(fn (Status $status): bool => self::release() !== null && ! $status->isBusy())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('updater::updater.confirm', ['version' => self::release()]))
            ->action(function (Status $status): void {
                $version = self::release();

                if ($version === null || $status->isBusy()) {
                    return;
                }

                $status->queue($version);
                RunUpdate::dispatch();

                Notification::make()->title(__('updater::updater.queued'))->success()->send();
            });
    }

    /** Updates the package passed as the "package" argument, or every package when the argument is missing. */
    public static function packages(string $name, bool $all): Action
    {
        return Action::make($name)
            ->label(__($all ? 'updater::updater.packages.update_all' : 'updater::updater.packages.update'))
            ->icon('heroicon-o-arrow-path')
            ->color($all ? 'warning' : 'primary')
            ->size($all ? null : 'sm')
            ->visible(fn (Status $status, PackageInventory $inventory): bool => ! $status->isBusy()
                && array_filter($inventory->all(), static fn (Package $package): bool => $package->canUpdate()) !== [])
            ->requiresConfirmation()
            ->modalDescription(fn (array $arguments): string => __('updater::updater.packages.confirm', [
                'package' => $all ? __('updater::updater.packages.all') : self::argument($arguments),
            ]))
            ->action(function (array $arguments, Status $status, PackageInventory $inventory) use ($all): void {
                $package = $all ? null : self::argument($arguments);
                $names = array_map(static fn (Package $item): string => $item->name, $inventory->all());

                // The argument comes from the browser: accept only installed direct dependencies.
                if ($status->isBusy() || ($package !== null && ! in_array($package, $names, true))) {
                    return;
                }

                $status->queue('composer: '.($package ?? '*'));
                UpdatePackages::dispatch($package);

                Notification::make()->title(__('updater::updater.queued'))->success()->send();
            });
    }

    /** Latest application version newer than the installed one, from the last check (no network). */
    private static function release(): ?string
    {
        try {
            return app(Updater::class)->available()?->version();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<mixed> $arguments */
    private static function argument(array $arguments): string
    {
        return is_string($arguments['package'] ?? null) ? $arguments['package'] : '';
    }
}
