<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;

/** Runs the update on a queue worker, away from web request time limits. */
final class RunUpdate implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** An update must never be retried blindly. */
    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public function __construct()
    {
        $this->timeout = $this->uniqueFor = Config::integer('updater.queue.timeout', 3600);

        $connection = Config::get('updater.queue.connection');
        $queue = Config::get('updater.queue.name');
        $this->onConnection(is_string($connection) && $connection !== '' ? $connection : null);
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
    }

    public function handle(Updater $updater): void
    {
        $updater->update();
    }
}
