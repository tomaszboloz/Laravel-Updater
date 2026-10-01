<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Process\Factory as Process;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\Process\PhpExecutableFinder;
use TomaszBoloz\LaravelUpdater\Jobs\RunTask;
use TomaszBoloz\LaravelUpdater\Packages\Package;

/**
 * Starts a task outside the web request: by default as a detached "php artisan updater:work" process, which needs
 * no queue worker and is not killed when the request ends; or as a queued job ("updater.runner" = "queue").
 */
final readonly class Background
{
    public const array TASKS = ['application', 'packages', 'check'];

    public function __construct(
        private Process $process,
        private Bus $bus,
        private Status $status,
        private Settings $settings,
        private string $basePath,
        private string $logPath,
        private string $runner = 'process',
    ) {}

    /** @param string|null $package a direct dependency for the "packages" task; null updates all */
    public function start(string $task, ?string $package = null): void
    {
        if (! in_array($task, self::TASKS, true) || ($package !== null && preg_match(Package::NAME_PATTERN, $package) !== 1)) {
            throw new UpdaterException(sprintf('Unknown task "%s".', $task));
        }

        if ($task !== 'check') {
            $this->status->queue($task === 'application' ? 'application' : 'composer: '.($package ?? '*'));
            $this->keepAdminOnline();
        }

        if ($this->runner === 'queue' || PHP_OS_FAMILY === 'Windows') {
            $this->bus->dispatch(new RunTask($task, $package));

            return;
        }

        $php = $this->settings->map('binaries')['php'] ?? (new PhpExecutableFinder)->find(false);
        $arguments = [...(preg_split('/\s+/', trim((string) $php)) ?: []), 'artisan', 'updater:work', $task, ...($package !== null ? ['--package='.$package] : [])];

        // Detached from the request: nohup + background "&", output to a log file instead of the request.
        $this->process->newPendingProcess()->path($this->basePath)->run(sprintf(
            'nohup %s >> %s 2>&1 &',
            implode(' ', array_map(escapeshellarg(...), $arguments)),
            escapeshellarg($this->logPath),
        ));
    }

    /**
     * Gives the admin who starts an update the maintenance-mode bypass cookie, so the panel keeps polling
     * the progress while the rest of the site shows the maintenance page.
     */
    private function keepAdminOnline(): void
    {
        $secret = $this->settings->maintenance()['secret'];

        if ($secret !== null && app()->bound('request') && app('request')->hasSession()) {
            Cookie::queue(MaintenanceModeBypassCookie::create($secret));
        }
    }
}
