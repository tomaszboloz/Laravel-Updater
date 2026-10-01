<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Concerns;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Filesystem\Filesystem;
use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\GitHub;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\Packages\PackageUpdater;
use TomaszBoloz\LaravelUpdater\Pipeline;
use TomaszBoloz\LaravelUpdater\State\StateStore;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;

/** Composer package monitoring and updates, plus the scheduled check. */
trait RegistersPackageServices
{
    private function registerPackageServices(): void
    {
        $this->app->bind(PackageInventory::class, fn (Application $app): PackageInventory => new PackageInventory(
            $app->make(Filesystem::class),
            $app->make(StateStore::class),
            $app->make(CommandRunner::class),
            $app->make(GitHub::class),
            $this->settings($app),
            $app->basePath(),
        ));

        $this->app->bind(PackageUpdater::class, fn (Application $app): PackageUpdater => new PackageUpdater(
            $app->make(Pipeline::class),
            $app->make(PackageInventory::class),
            $app->make(Filesystem::class),
            $app->make('events'),
            $app->basePath(),
            $this->settings($app)->commands('package_steps'),
            $this->settings($app)->commands('package_recovery_steps'),
        ));

        $this->app->bind(UpdateChecker::class, fn (Application $app): UpdateChecker => new UpdateChecker(
            $app->make(Updater::class),
            $app->make(PackageInventory::class),
            $app->make(StateStore::class),
            $app->make('events'),
        ));
    }

    /** Automatic monitoring: the check runs from Laravel's scheduler (requires "schedule:run" in cron). */
    public function packageBooted(): void
    {
        // The maintenance bypass cookie is signed by Laravel itself and read before cookies are decrypted.
        EncryptCookies::except('laravel_maintenance');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule, Application $app): void {
            if (($cron = $this->settings($app)->configString('schedule')) !== null) {
                $schedule->command('updater:check')->cron($cron)->withoutOverlapping()->runInBackground();
            }
        });
    }
}
