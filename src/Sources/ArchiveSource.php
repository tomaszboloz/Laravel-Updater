<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Sources;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use TomaszBoloz\LaravelUpdater\GitHub;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\UpdaterException;
use ZipArchive;

/**
 * Downloads the release zipball and copies it over the application (for hosts without git).
 * A manifest of shipped files lets the next update delete files a release dropped.
 * There is no automatic rollback: keep backups.
 */
final readonly class ArchiveSource implements Source
{
    /** @param list<string> $preserve paths relative to $basePath that are never overwritten */
    public function __construct(
        private GitHub $github,
        private Filesystem $files,
        private string $basePath,
        private string $workPath,
        private string $manifestPath,
        private array $preserve = [],
    ) {}

    public function snapshot(): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            throw UpdaterException::missingExtension('zip');
        }

        return null;
    }

    public function apply(Release $release): void
    {
        $work = $this->workPath.DIRECTORY_SEPARATOR.Str::random(16);
        $this->files->ensureDirectoryExists($work, 0700);

        try {
            $archive = $work.DIRECTORY_SEPARATOR.'release.zip';
            $this->github->downloadArchive($release, $archive);
            $shipped = $this->copy($this->extract($archive, $work.DIRECTORY_SEPARATOR.'src'));
            $this->prune($shipped);
            $this->files->put($this->manifestPath, json_encode($shipped, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } finally {
            $this->files->deleteDirectory($work);
        }
    }

    public function restore(string $snapshot): void
    {
        // Snapshots are never taken for archives, so there is nothing to restore.
    }

    /** Extracts after rejecting entries that could escape the target directory ("zip slip"). */
    private function extract(string $archive, string $target): string
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw UpdaterException::unreadableArchive();
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string) $zip->getNameIndex($index);

                if (self::isUnsafe($entry)) {
                    throw UpdaterException::unsafeArchive($entry);
                }
            }

            $zip->extractTo($target);
        } finally {
            $zip->close();
        }

        // GitHub zipballs contain a single "owner-repository-sha/" directory.
        $roots = $this->files->directories($target);

        if (count($roots) !== 1 || ! is_string($roots[0]) || $this->files->files($target) !== []) {
            throw UpdaterException::unreadableArchive();
        }

        return $roots[0];
    }

    /** @return list<string> paths the release ships, relative to $basePath (preserved paths excluded) */
    private function copy(string $source): array
    {
        $shipped = [];

        foreach ($this->files->allFiles($source, true) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if ($this->isPreserved($relative)) {
                continue;
            }

            $shipped[] = $relative;

            if (! $file->isLink()) {
                $target = $this->basePath.DIRECTORY_SEPARATOR.$relative;
                $this->files->ensureDirectoryExists(dirname($target));
                $this->files->copy($file->getPathname(), $target);
            }
        }

        return $shipped;
    }

    /**
     * Deletes files the previous archive update shipped that this release no longer contains.
     * Without a manifest (first archive update) nothing is deleted: the owner of other files is unknown.
     *
     * @param  list<string>  $shipped
     */
    private function prune(array $shipped): void
    {
        if (! $this->files->isFile($this->manifestPath)) {
            return;
        }

        $manifest = json_decode($this->files->get($this->manifestPath), true);
        $previous = is_array($manifest) ? array_filter($manifest, is_string(...)) : [];

        foreach (array_diff($previous, $shipped) as $relative) {
            if (self::isUnsafe($relative) || $this->isPreserved($relative)) {
                continue;
            }

            $target = $this->basePath.DIRECTORY_SEPARATOR.$relative;

            if (is_link($target) || $this->files->isFile($target)) {
                $this->files->delete($target);
                $this->removeEmptyDirectories(dirname($target));
            }
        }
    }

    private function removeEmptyDirectories(string $directory): void
    {
        while (str_starts_with($directory, $this->basePath.DIRECTORY_SEPARATOR) && $this->files->isEmptyDirectory($directory)) {
            $this->files->deleteDirectory($directory);
            $directory = dirname($directory);
        }
    }

    /** True for paths that could escape the application directory. */
    private static function isUnsafe(string $path): bool
    {
        return $path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true) || preg_match('/\A[A-Za-z]:/', $path) === 1;
    }

    private function isPreserved(string $relative): bool
    {
        foreach ($this->preserve as $path) {
            $path = trim(str_replace('\\', '/', $path), '/');

            if ($path !== '' && ($relative === $path || str_starts_with($relative, $path.'/'))) {
                return true;
            }
        }

        return false;
    }
}
