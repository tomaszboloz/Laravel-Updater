<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Filesystem\Filesystem;

/** Remembers the installed version in storage; falls back to the configured version before the first update. */
final readonly class VersionStore
{
    public function __construct(private Filesystem $files, private string $path, private string $fallback = '0.0.0') {}

    public function current(): string
    {
        $stored = $this->files->exists($this->path) ? trim($this->files->get($this->path)) : '';

        return Release::normalize(preg_match(Release::TAG_PATTERN, $stored) === 1 ? $stored : $this->fallback);
    }

    public function remember(Release $release): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $release->version(), lock: true);
    }
}
