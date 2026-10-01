<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Throwable;
use TomaszBoloz\LaravelUpdater\Events\UpdatesAvailable;
use TomaszBoloz\LaravelUpdater\Packages\Package;
use TomaszBoloz\LaravelUpdater\Packages\PackageInventory;
use TomaszBoloz\LaravelUpdater\State\StateStore;

/** One check of everything: the application release and every direct package. Runs on demand and on schedule. */
final readonly class UpdateChecker
{
    private const string FILE = 'checker';

    public function __construct(
        private Updater $updater,
        private PackageInventory $inventory,
        private StateStore $state,
        private Dispatcher $events,
    ) {}

    /** @return array{release: Release|null, packages: list<Package>, errors: list<string>} */
    public function check(): array
    {
        $this->markChecking();
        $errors = [];
        $release = null;

        try {
            try {
                $release = $this->updater->available(fresh: true);
                $this->state->put(self::FILE, [...$this->state->get(self::FILE), 'latest_app' => $release?->tag]);
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
            $this->state->put(self::FILE, [...$this->state->get(self::FILE), 'checking_since' => null]);
        }
    }

    /** Marks a check as started before its queued job runs, so the panel can show progress at once. */
    public function markChecking(): void
    {
        $this->state->put(self::FILE, [...$this->state->get(self::FILE), 'checking_since' => Carbon::now()->toIso8601String()]);
    }

    /** A check started in the last 15 minutes and not finished (a killed check stops counting after that). */
    public function isChecking(): bool
    {
        $since = $this->state->get(self::FILE)['checking_since'] ?? null;

        return is_string($since) && Carbon::parse($since)->addMinutes(15)->isFuture();
    }

    /** From the last check only (no network), e.g. for the navigation badge. */
    public function availableCount(): int
    {
        $tag = $this->state->get(self::FILE)['latest_app'] ?? null;
        $app = is_string($tag) && (new Release($tag, $tag))->isNewerThan($this->updater->currentVersion());

        return (int) $app + count(array_filter($this->inventory->all(), static fn (Package $package): bool => $package->hasUpdate()));
    }
}
