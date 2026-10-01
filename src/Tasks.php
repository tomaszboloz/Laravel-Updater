<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Container\Container;
use Throwable;
use TomaszBoloz\LaravelUpdater\Events\PackagesUpdated;
use TomaszBoloz\LaravelUpdater\Events\PackagesUpdateFailed;
use TomaszBoloz\LaravelUpdater\Events\UpdateFailed;
use TomaszBoloz\LaravelUpdater\Events\UpdatesAvailable;
use TomaszBoloz\LaravelUpdater\Events\UpdateSucceeded;
use TomaszBoloz\LaravelUpdater\Packages\PackageUpdater;

/**
 * Runs a background task (from "updater:work" or the queued RunTask job).
 *
 * Composer replaces vendor/ while this process is running, so everything needed after that is loaded up front,
 * and the follow-up check runs in a fresh process that boots the new code.
 */
final readonly class Tasks
{
    private const array PRELOAD = [
        UpdaterException::class, UpdateSucceeded::class, UpdateFailed::class,
        PackagesUpdated::class, PackagesUpdateFailed::class, UpdatesAvailable::class, Status::class,
    ];

    public function __construct(private Container $container, private CommandRunner $runner, private Status $status) {}

    public function perform(string $task, ?string $package = null): void
    {
        foreach (self::PRELOAD as $class) {
            class_exists($class);
        }

        match ($task) {
            'application' => $this->container->make(Updater::class)->update(),
            'packages' => $this->container->make(PackageUpdater::class)->update($package),
            'check' => $this->container->make(UpdateChecker::class)->check(),
            default => throw new UpdaterException(sprintf('Unknown task "%s".', $task)),
        };

        if ($task !== 'check') {
            try {
                $this->runner->run(['@php', 'artisan', 'updater:check']);
            } catch (Throwable $exception) {
                $this->status->append('! '.$this->runner->redact($exception->getMessage()));
            }
        }
    }
}
