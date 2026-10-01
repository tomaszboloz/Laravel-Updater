<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Events;

use TomaszBoloz\LaravelUpdater\Release;

final readonly class UpdateSucceeded
{
    public function __construct(public Release $release) {}
}
