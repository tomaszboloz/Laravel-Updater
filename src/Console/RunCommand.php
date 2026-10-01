<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;
use TomaszBoloz\LaravelUpdater\Background;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;

final class RunCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'updater:run
        {--background : Start the update in the background (detached process or queue) and return}
        {--force : Run in production without confirmation}';

    protected $description = 'Install the latest GitHub release: code, Composer, migrations, npm build and caches';

    public function handle(Updater $updater, Status $status, UpdateChecker $checker, Background $background): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        if ($this->option('background')) {
            $release = $updater->available(fresh: true);

            if ($release !== null) {
                $background->start('application');
            }

            $this->components->info($release === null ? 'The application is up to date.' : "Update to {$release->version()} started in the background.");

            return self::SUCCESS;
        }

        try {
            $release = $updater->update();
        } catch (Throwable $exception) {
            $this->line(implode(PHP_EOL, $status->get()['log']));
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $checker->check();
        $this->components->info($release === null ? 'The application is up to date.' : "Updated to {$release->version()}.");

        return self::SUCCESS;
    }
}
