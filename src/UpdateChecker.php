<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use TomaszBoloz\LaravelUpdater\Events\UpdatesAvailable;
use TomaszBoloz\LaravelUpdater\Packages\Package;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;

/** One check of everything: the application release and every direct package. Runs on demand and on schedule. */
final readonly class UpdateChecker
{
    private const string LATEST_APP = 'updater:app-latest';

    private const string CHECKING = 'updater:checking';

    public function __construct(
        private Updater $updater,
        private PackageInventory $inventory,
        private Cache $cache,
        private Dispatcher $events,
    ) {}

    /** @return array{release: Release|null, packages: list<Package>, errors: list<string>} */
    public function check(): array
    {
        $this->cache->put(self::CHECKING, true, 900);
        $errors = [];
        $release = null;

        try {
            try {
                $release = $this->updater->available(fresh: true);
                $this->cache->forever(self::LATEST_APP, $release?->tag);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }

            $check = $this->inventory->check();

            if ($check['error'] !== null) {
                $errors[] = $check['error'];
            }

            $packages = array_values(array_filter($this->inventory->all(), static fn (Package $package): bool => $package->hasUpdate()));

            if ($release !== null || $packages !== []) {
                $this->events->dispatch(new UpdatesAvailable($release, $packages));
            }

            return ['release' => $release, 'packages' => $packages, 'errors' => $errors];
        } finally {
            $this->cache->forget(self::CHECKING);
        }
    }

    /** Marks a check as started before its queued job runs, so the panel can show progress at once. */
    public function markChecking(): void
    {
        $this->cache->put(self::CHECKING, true, 900);
    }

    public function isChecking(): bool
    {
        return $this->cache->get(self::CHECKING) === true;
    }

    /** From cached results only (no network), e.g. for the navigation badge. */
    public function availableCount(): int
    {
        $tag = $this->cache->get(self::LATEST_APP);
        $app = is_string($tag) && (new Release($tag, $tag))->isNewerThan($this->updater->currentVersion());

        return (int) $app + count(array_filter($this->inventory->all(), static fn (Package $package): bool => $package->hasUpdate()));
    }
}
