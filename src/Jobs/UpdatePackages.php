<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TomaszBoloz\LaravelUpdater\Packages\PackageUpdater;
use TomaszBoloz\LaravelUpdater\UpdateChecker;

/** Updates one package (or all when $package is null), then refreshes the list of available updates. */
final class UpdatePackages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use UsesUpdaterQueue;

    public function __construct(public readonly ?string $package = null)
    {
        $this->useUpdaterQueue();
    }

    public function uniqueId(): string
    {
        return $this->package ?? '*';
    }

    public function handle(PackageUpdater $updater, UpdateChecker $checker): void
    {
        $updater->update($this->package);
        $checker->check();
    }
}
