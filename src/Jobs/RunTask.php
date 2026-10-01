<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TomaszBoloz\LaravelUpdater\Tasks;

/** Queue alternative to the detached "updater:work" process ("updater.runner" = "queue"). */
final class RunTask implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use UsesUpdaterQueue;

    public function __construct(public readonly string $task, public readonly ?string $package = null)
    {
        $this->useUpdaterQueue();
    }

    public function uniqueId(): string
    {
        return $this->task.':'.($this->package ?? '*');
    }

    public function handle(Tasks $tasks): void
    {
        $tasks->perform($this->task, $this->package);
    }
}
