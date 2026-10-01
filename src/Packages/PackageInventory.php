<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Packages;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use JsonException;
use Throwable;
use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\GitHub;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Settings;
use TomaszBoloz\LaravelUpdater\State\StateStore;

/**
 * Installed direct Composer dependencies and their latest versions: "composer outdated" for every package,
 * plus GitHub tags for monitored private packages (their own tokens). The last check is kept in a state file.
 *
 * @phpstan-type Check array{checked_at: string|null, error: string|null, latest: array<string, array{latest: string, status: string}>}
 */
final readonly class PackageInventory
{
    private const string FILE = 'packages';

    public function __construct(
        private Filesystem $files,
        private StateStore $state,
        private CommandRunner $runner,
        private GitHub $github,
        private Settings $settings,
        private string $basePath,
    ) {}

    /** @return list<Package> sorted by name */
    public function all(): array
    {
        $root = $this->json('composer.json');
        $dev = array_keys((array) ($root['require-dev'] ?? []));
        $installed = $this->installedVersions();
        $private = array_column($this->settings->packages(), 'name');
        $latest = $this->lastCheck()['latest'];
        $packages = [];

        foreach ([...array_keys((array) ($root['require'] ?? [])), ...$dev] as $name) {
            if (is_string($name) && isset($installed[$name])) {
                $packages[$name] = new Package($name, $installed[$name], in_array($name, $dev, true), in_array($name, $private, true), $latest[$name]['latest'] ?? null, $latest[$name]['status'] ?? null);
            }
        }

        ksort($packages);

        return array_values($packages);
    }

    /** @return Check */
    public function lastCheck(): array
    {
        $check = $this->state->get(self::FILE);
        $latest = [];

        foreach ((array) ($check['latest'] ?? []) as $name => $row) {
            if (is_string($name) && is_array($row) && is_string($row['latest'] ?? null) && is_string($row['status'] ?? null)) {
                $latest[$name] = ['latest' => $row['latest'], 'status' => $row['status']];
            }
        }

        return [
            'checked_at' => is_string($check['checked_at'] ?? null) ? $check['checked_at'] : null,
            'error' => is_string($check['error'] ?? null) ? $check['error'] : null,
            'latest' => $latest,
        ];
    }

    /** @return Check */
    public function check(): array
    {
        $latest = [];
        $errors = [];

        try {
            $outdated = json_decode($this->runner->run(['@composer', 'outdated', '--direct', '--format=json', '--no-interaction']), true, 16, JSON_THROW_ON_ERROR);

            foreach ((array) (is_array($outdated) ? ($outdated['installed'] ?? []) : []) as $row) {
                if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['latest'] ?? null)) {
                    $latest[$row['name']] = ['latest' => $row['latest'], 'status' => is_string($row['latest-status'] ?? null) ? $row['latest-status'] : Package::MAJOR];
                }
            }
        } catch (Throwable $exception) {
            $errors[] = $this->runner->redact($exception->getMessage());
        }

        $installed = $this->installedVersions();

        foreach ($this->settings->packages() as $package) {
            try {
                $tag = $this->github->forRepository($package['repository'], $package['token'])->latestTag(fresh: true);
            } catch (Throwable $exception) {
                $errors[] = $package['name'].': '.$this->runner->redact($exception->getMessage());

                continue;
            }

            $current = $installed[$package['name']] ?? null;

            if ($tag !== null && $current !== null && version_compare(Release::normalize($tag), Release::normalize($current), '>')) {
                $sameMajor = strtok(Release::normalize($tag), '.') === strtok(Release::normalize($current), '.');
                $latest[$package['name']] = ['latest' => $tag, 'status' => $sameMajor ? Package::SAFE : Package::MAJOR];
            }
        }

        $check = ['checked_at' => Carbon::now()->toIso8601String(), 'error' => $errors === [] ? null : implode("\n", $errors), 'latest' => $latest];
        $this->state->put(self::FILE, $check);

        return $check;
    }

    /** @return array<string, string> name => installed version */
    private function installedVersions(): array
    {
        $installed = $this->json('vendor/composer/installed.json');
        $versions = [];

        foreach ((array) ($installed['packages'] ?? $installed) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }

    /** @return array<mixed> */
    private function json(string $path): array
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.$path;

        try {
            $data = $this->files->exists($path) ? json_decode($this->files->get($path), true, 64, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
