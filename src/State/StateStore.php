<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\State;

use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Updater state kept as JSON files in storage/app/updater. Unlike the cache, files survive "cache:clear" and
 * "optimize:clear", which the update steps themselves run, and are shared by web requests and background processes.
 */
final readonly class StateStore
{
    public function __construct(private Filesystem $files, private string $directory) {}

    /** @return array<string, mixed> */
    public function get(string $name): array
    {
        $path = $this->path($name);

        try {
            $data = $this->files->exists($path) ? json_decode($this->files->get($path), true, 16, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? array_filter($data, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /** @param array<string, mixed> $data written atomically, so readers never see a half-written file */
    public function put(string $name, array $data): void
    {
        $this->files->ensureDirectoryExists($this->directory, 0700);
        $this->files->replace($this->path($name), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0600);
    }

    public function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.preg_replace('/[^a-z0-9-]/', '', $name).'.json';
    }

    public function directory(): string
    {
        return $this->directory;
    }
}
