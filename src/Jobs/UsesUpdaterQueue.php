<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Jobs;

use Illuminate\Support\Facades\Config;

/** Queue connection, queue name and timeout from config/updater.php ("queue"). */
trait UsesUpdaterQueue
{
    /** Updates and checks must never be retried blindly. */
    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    protected function useUpdaterQueue(): void
    {
        $this->timeout = $this->uniqueFor = Config::integer('updater.queue.timeout', 3600);

        $connection = Config::get('updater.queue.connection');
        $queue = Config::get('updater.queue.name');
        $this->onConnection(is_string($connection) && $connection !== '' ? $connection : null);
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
    }
}
