<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TomaszBoloz\LaravelUpdater\UpdateChecker;

/** Checks the application and every package in the background ("composer outdated" can take a while). */
final class CheckForUpdates implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use UsesUpdaterQueue;

    public function __construct()
    {
        $this->useUpdaterQueue();
    }

    public function handle(UpdateChecker $checker): void
    {
        $checker->check();
    }
}
