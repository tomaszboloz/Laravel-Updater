<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use TomaszBoloz\LaravelUpdater\Background;
use TomaszBoloz\LaravelUpdater\Packages\Package;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;

/** Check and update actions of the Updates page. Each one starts a background task; the page polls its status. */
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
            ->action(function (UpdateChecker $checker, Background $background): void {
                $checker->markChecking();
                $background->start('check');

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
            ->action(function (Status $status, Background $background): void {
                if (self::release() === null || $status->isBusy()) {
                    return;
                }

                $background->start('application');

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
            ->action(function (array $arguments, Status $status, PackageInventory $inventory, Background $background) use ($all): void {
                $package = $all ? null : self::argument($arguments);
                $names = array_map(static fn (Package $item): string => $item->name, $inventory->all());

                // The argument comes from the browser: accept only installed direct dependencies.
                if ($status->isBusy() || ($package !== null && ! in_array($package, $names, true))) {
                    return;
                }

                $background->start('packages', $package);

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
