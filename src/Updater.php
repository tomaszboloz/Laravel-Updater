<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use TomaszBoloz\LaravelUpdater\Events\UpdateFailed;
use TomaszBoloz\LaravelUpdater\Events\UpdateSucceeded;
use TomaszBoloz\LaravelUpdater\Sources\Source;

/** Updates the whole application to a GitHub release; on failure rolls the code back (when the source can). */
final readonly class Updater
{
    /**
     * @param  list<list<string>>  $steps
     * @param  list<list<string>>  $recoverySteps
     */
    public function __construct(
        private GitHub $github,
        private Source $source,
        private Pipeline $pipeline,
        private VersionStore $versions,
        private Dispatcher $events,
        private array $steps,
        private array $recoverySteps = [],
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

        try {
            $this->pipeline->run(
                $release->version(),
                work: function () use ($release): void {
                    $this->pipeline->step('install '.$release->tag);
                    $this->source->apply($release);

                    foreach ($this->steps as $step) {
                        $this->pipeline->execute($step);
                    }

                    $this->versions->remember($release);
                },
                // Database changes are not reverted: write backward-compatible migrations and keep backups.
                recover: function (mixed $snapshot): void {
                    if (! is_string($snapshot)) {
                        return;
                    }

                    $this->pipeline->step('rollback '.$snapshot);
                    $this->source->restore($snapshot);

                    foreach ($this->recoverySteps as $step) {
                        $this->pipeline->attempt($step);
                    }
                },
                prepare: fn (): ?string => $this->source->snapshot(), // validates before any downtime
            );
        } catch (Throwable $exception) {
            $this->events->dispatch(new UpdateFailed($release, $exception));

            throw $exception;
        }

        $this->events->dispatch(new UpdateSucceeded($release));

        return $release;
    }
}
