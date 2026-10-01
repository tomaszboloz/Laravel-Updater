<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Console;

use Illuminate\Console\Command;
use Throwable;
use TomaszBoloz\LaravelUpdater\Background;
use TomaszBoloz\LaravelUpdater\Tasks;

/** Entry point of the detached background process started from the admin panel. */
final class WorkCommand extends Command
{
    protected $signature = 'updater:work
        {task : application, packages or check}
        {--package= : Package to update (the "packages" task updates all when omitted)}';

    protected $description = 'Run an updater task in the background (started by the admin panel)';

    protected $hidden = true;

    public function handle(Tasks $tasks): int
    {
        $task = $this->argument('task');
        $task = is_string($task) ? $task : '';
        $package = $this->option('package');

        if (! in_array($task, Background::TASKS, true)) {
            $this->components->error("Unknown task \"{$task}\".");

            return self::INVALID;
        }

        try {
            $tasks->perform($task, is_string($package) && $package !== '' ? $package : null);
        } catch (Throwable $exception) {
            // The status file already holds the details for the panel.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
