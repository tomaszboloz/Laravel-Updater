<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Console;

use Illuminate\Console\Command;
use Throwable;
use TomaszBoloz\LaravelUpdater\Updater;

final class CheckCommand extends Command
{
    protected $signature = 'updater:check';

    protected $description = 'Check GitHub for a newer release of the application';

    public function handle(Updater $updater): int
    {
        try {
            $release = $updater->available(fresh: true);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Installed version', $updater->currentVersion());

        if ($release === null) {
            $this->components->info('The application is up to date.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Available version', $release->version());
        $this->components->warn('An update is available. Run "php artisan updater:run" to install it.');

        return self::SUCCESS;
    }
}
