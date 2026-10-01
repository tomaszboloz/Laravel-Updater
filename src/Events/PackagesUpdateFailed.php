<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Events;

use Throwable;

/** $package is null when every package was being updated. */
final readonly class PackagesUpdateFailed
{
    public function __construct(public ?string $package, public Throwable $exception) {}
}
