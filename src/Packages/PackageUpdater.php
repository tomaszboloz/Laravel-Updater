<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Packages;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Throwable;
use TomaszBoloz\LaravelUpdater\Events\PackagesUpdated;
use TomaszBoloz\LaravelUpdater\Events\PackagesUpdateFailed;
use TomaszBoloz\LaravelUpdater\Pipeline;
use TomaszBoloz\LaravelUpdater\UpdaterException;

/** "composer update" for one direct dependency or all of them; restores composer.lock when anything fails. */
final readonly class PackageUpdater
{
    /**
     * @param  list<list<string>>  $steps  "{packages}" expands to the package name, or disappears when updating all
     * @param  list<list<string>>  $recoverySteps  run after composer.lock has been restored
     */
    public function __construct(
        private Pipeline $pipeline,
        private PackageInventory $inventory,
        private Filesystem $files,
        private Dispatcher $events,
        private string $basePath,
        private array $steps,
        private array $recoverySteps = [],
    ) {}

    public function update(?string $package = null): void
    {
        // Only direct dependencies of this application can be named, which also rules out option injection.
        if ($package !== null && ! in_array($package, array_map(static fn (Package $item): string => $item->name, $this->inventory->all()), true)) {
            throw new UpdaterException(sprintf('"%s" is not a direct dependency of this application.', $package));
        }

        $lockFile = $this->basePath.DIRECTORY_SEPARATOR.'composer.lock';
        $backup = $this->files->exists($lockFile) ? $this->files->get($lockFile) : null;

        try {
            $this->pipeline->run(
                'composer: '.($package ?? '*'),
                work: function () use ($package): void {
                    foreach ($this->steps as $step) {
                        $this->pipeline->execute($this->expand($step, $package));
                    }
                },
                recover: function () use ($lockFile, $backup): void {
                    if ($backup === null) {
                        return;
                    }

                    $this->pipeline->step('restore composer.lock');
                    $this->files->put($lockFile, $backup);

                    foreach ($this->recoverySteps as $step) {
                        $this->pipeline->attempt($step);
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->events->dispatch(new PackagesUpdateFailed($package, $exception));

            throw $exception;
        }

        $this->events->dispatch(new PackagesUpdated($package));
    }

    /**
     * @param  list<string>  $step
     * @return list<string>
     */
    private function expand(array $step, ?string $package): array
    {
        $command = [];

        foreach ($step as $argument) {
            if ($argument !== '{packages}') {
                $command[] = $argument;
            } elseif ($package !== null) {
                $command[] = $package;
            }
        }

        return $command;
    }
}
