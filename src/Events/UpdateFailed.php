<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Events;

use Throwable;
use TomaszBoloz\LaravelUpdater\Release;

final readonly class UpdateFailed
{
    public function __construct(public Release $release, public Throwable $exception) {}
}
