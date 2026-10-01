<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Console;

use Illuminate\Console\Command;
use TomaszBoloz\LaravelUpdater\UpdateChecker;
use TomaszBoloz\LaravelUpdater\Updater;

/** Checks the application release and every package; also runs on the schedule from config("updater.schedule"). */
final class CheckCommand extends Command
{
    protected $signature = 'updater:check';

    protected $description = 'Check GitHub and Composer for a newer application release and package versions';

    public function handle(UpdateChecker $checker, Updater $updater): int
    {
        $result = $checker->check();

        $this->components->twoColumnDetail('Application', $updater->currentVersion().($result['release'] === null ? '' : ' → '.$result['release']->version()));

        foreach ($result['packages'] as $package) {
            $this->components->twoColumnDetail($package->name.($package->private ? ' (private)' : ''), $package->version.' → '.$package->latest.($package->canUpdate() ? '' : ' (major)'));
        }

        foreach ($result['errors'] as $error) {
            $this->components->error($error);
        }

        if ($result['release'] === null && $result['packages'] === []) {
            $this->components->info('Everything is up to date.');
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
