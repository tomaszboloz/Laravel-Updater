<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;
use TomaszBoloz\LaravelUpdater\Background;
use TomaszBoloz\LaravelUpdater\Packages\PackageUpdater;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\UpdateChecker;

final class PackagesCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'updater:packages
        {package? : A direct dependency, e.g. vendor/package; all packages when omitted}
        {--background : Start the update in the background (detached process or queue) and return}
        {--force : Run in production without confirmation}';

    protected $description = 'Update one Composer package or all of them, then migrate and clear caches';

    public function handle(PackageUpdater $updater, UpdateChecker $checker, Status $status, Background $background): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $package = $this->argument('package');
        $package = is_string($package) && $package !== '' ? $package : null;

        if ($this->option('background')) {
            $background->start('packages', $package);
            $this->components->info('Package update started in the background.');

            return self::SUCCESS;
        }

        try {
            $updater->update($package);
        } catch (Throwable $exception) {
            $this->line(implode(PHP_EOL, $status->get()['log']));
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $checker->check();
        $this->components->info(($package ?? 'All packages').' updated.');

        return self::SUCCESS;
    }
}
