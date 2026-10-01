<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Events;

/** $package is null when every package was updated. */
final readonly class PackagesUpdated
{
    public function __construct(public ?string $package) {}
}
