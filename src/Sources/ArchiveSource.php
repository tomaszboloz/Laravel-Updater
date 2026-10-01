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
 * Files removed in the release are left in place and there is no automatic rollback: keep backups.
 */
final readonly class ArchiveSource implements Source
{
    /** @param list<string> $preserve paths relative to $basePath that are never overwritten */
    public function __construct(
        private GitHub $github,
        private Filesystem $files,
        private string $basePath,
        private string $workPath,
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
            $this->copy($this->extract($archive, $work.DIRECTORY_SEPARATOR.'src'));
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
                $segments = explode('/', $entry);

                if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '\\')
                    || str_contains($entry, "\0") || in_array('..', $segments, true) || preg_match('/\A[A-Za-z]:/', $entry) === 1) {
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

    private function copy(string $source): void
    {
        foreach ($this->files->allFiles($source, true) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if ($file->isLink() || $this->isPreserved($relative)) {
                continue;
            }

            $target = $this->basePath.DIRECTORY_SEPARATOR.$relative;
            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->copy($file->getPathname(), $target);
        }
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
