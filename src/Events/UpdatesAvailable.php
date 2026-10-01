<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Events;

use TomaszBoloz\LaravelUpdater\Packages\Package;
use TomaszBoloz\LaravelUpdater\Release;

/** Dispatched by a check (manual or scheduled) that found something newer; listen to it to send notifications. */
final readonly class UpdatesAvailable
{
    /** @param list<Package> $packages */
    public function __construct(public ?Release $release, public array $packages) {}
}
