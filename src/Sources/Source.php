<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Sources;

use TomaszBoloz\LaravelUpdater\Release;

/** Puts the code of a release in place. */
interface Source
{
    /** Validates the installation and returns a restore point, or null when rollback is not supported. */
    public function snapshot(): ?string;

    public function apply(Release $release): void;

    public function restore(string $snapshot): void;
}
