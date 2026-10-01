<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Sources;

use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\UpdaterException;

/** The application is a git checkout of the repository: fetch the release tag and check it out (credentials come from the runner). */
final readonly class GitSource implements Source
{
    /** @param list<string> $ignoredChanges tracked files whose local changes are expected, e.g. composer.lock after package updates */
    public function __construct(private CommandRunner $runner, private array $ignoredChanges = ['composer.lock']) {}

    public function snapshot(): string
    {
        $changes = array_filter(
            explode("\n", $this->runner->run(['@git', 'status', '--porcelain', '--untracked-files=no'])),
            fn (string $line): bool => trim($line) !== '' && ! in_array(substr($line, 3), $this->ignoredChanges, true),
        );

        if ($changes !== []) {
            throw UpdaterException::dirtyWorkingTree();
        }

        return trim($this->runner->run(['@git', 'rev-parse', '--verify', 'HEAD']));
    }

    public function apply(Release $release): void
    {
        // The tag is validated by Release, so it cannot be mistaken for an option.
        $ref = 'refs/tags/'.$release->tag;

        $this->runner->run(['@git', 'fetch', '--force', '--no-tags', 'origin', "+{$ref}:{$ref}"]);
        $this->runner->run(['@git', '-c', 'advice.detachedHead=false', 'checkout', '--force', $ref]);
    }

    public function restore(string $snapshot): void
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/', $snapshot) !== 1) {
            throw new UpdaterException(sprintf('Invalid restore point "%s".', $snapshot));
        }

        $this->runner->run(['@git', '-c', 'advice.detachedHead=false', 'checkout', '--force', $snapshot]);
    }
}
