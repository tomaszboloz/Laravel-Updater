<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use TomaszBoloz\LaravelUpdater\Events\UpdateFailed;
use TomaszBoloz\LaravelUpdater\Events\UpdateSucceeded;
use TomaszBoloz\LaravelUpdater\Sources\Source;

/** Lock, maintenance mode, new code, steps, new version; on failure rollback, recovery steps, site back up. */
final readonly class Updater
{
    /**
     * @param  list<list<string>>  $steps
     * @param  list<list<string>>  $recoverySteps
     * @param  array{enabled?: bool, retry?: int, secret?: string|null}  $maintenance
     */
    public function __construct(
        private GitHub $github,
        private Source $source,
        private CommandRunner $runner,
        private VersionStore $versions,
        private Status $status,
        private LockProvider $locks,
        private Dispatcher $events,
        private array $steps,
        private array $recoverySteps = [],
        private array $maintenance = [],
        private int $lockSeconds = 3600,
    ) {}

    public function currentVersion(): string
    {
        return $this->versions->current();
    }

    /** The latest release when it is newer than the installed version. */
    public function available(bool $fresh = false): ?Release
    {
        $release = $this->github->latestRelease($fresh);

        return $release?->isNewerThan($this->currentVersion()) === true ? $release : null;
    }

    /** Installs the given (or latest available) release; returns null when already up to date. */
    public function update(?Release $release = null): ?Release
    {
        $release ??= $this->available(fresh: true);

        if ($release === null) {
            return null;
        }

        $lock = $this->locks->lock('updater:lock', $this->lockSeconds);

        if (! $lock->get()) {
            throw UpdaterException::alreadyRunning();
        }

        [$snapshot, $down] = [null, false];

        try {
            $this->status->start($release->version());
            $snapshot = $this->source->snapshot(); // validates before any downtime
            $down = $this->down();
            $this->status->step('install '.$release->tag);
            $this->source->apply($release);

            foreach ($this->steps as $step) {
                $this->execute($step);
            }

            $this->versions->remember($release);
            $this->status->finish(Status::SUCCEEDED);
            $this->events->dispatch(new UpdateSucceeded($release));

            return $release;
        } catch (Throwable $exception) {
            $message = $this->runner->redact($exception->getMessage());
            $this->status->append('! '.$message);
            $this->recover($snapshot);
            $this->status->finish(Status::FAILED, $message);
            $this->events->dispatch(new UpdateFailed($release, $exception));

            throw $exception;
        } finally {
            if ($down) {
                $this->attempt(['@php', 'artisan', 'up']);
            }

            $lock->release();
        }
    }

    private function down(): bool
    {
        if (! ($this->maintenance['enabled'] ?? true)) {
            return false;
        }

        $secret = $this->maintenance['secret'] ?? null;
        $this->execute(['@php', 'artisan', 'down', '--retry='.($this->maintenance['retry'] ?? 60), ...($secret ? ['--secret='.$secret] : [])]);

        return true;
    }

    /** Database changes are not reverted: write backward-compatible migrations and keep backups. */
    private function recover(?string $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        try {
            $this->status->step('rollback '.$snapshot);
            $this->source->restore($snapshot);
        } catch (Throwable $exception) {
            $this->status->append('! '.$this->runner->redact($exception->getMessage()));

            return;
        }

        foreach ($this->recoverySteps as $step) {
            $this->attempt($step);
        }
    }

    /** @param list<string> $step */
    private function execute(array $step): void
    {
        $this->status->step($this->runner->describe($step));
        $this->runner->run($step, onOutput: $this->status->append(...));
    }

    /** @param list<string> $step */
    private function attempt(array $step): void
    {
        try {
            $this->execute($step);
        } catch (Throwable $exception) {
            $this->status->append('! '.$this->runner->redact($exception->getMessage()));
        }
    }
}
