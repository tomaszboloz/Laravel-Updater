<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Fixtures;

use RuntimeException;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Sources\Source;

final class FakeSource implements Source
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly ?string $snapshot = 'abc', private readonly bool $failApply = false, private readonly bool $failRestore = false) {}

    public function snapshot(): ?string
    {
        $this->calls[] = 'snapshot';

        return $this->snapshot;
    }

    public function apply(Release $release): void
    {
        $this->calls[] = 'apply '.$release->tag;

        if ($this->failApply) {
            throw new RuntimeException('download failed');
        }
    }

    public function restore(string $snapshot): void
    {
        $this->calls[] = 'restore '.$snapshot;

        if ($this->failRestore) {
            throw new RuntimeException('restore failed');
        }
    }
}
