<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Http;

use Illuminate\Http\JsonResponse;
use TomaszBoloz\LaravelUpdater\Authorization;
use TomaszBoloz\LaravelUpdater\Status;
use TomaszBoloz\LaravelUpdater\UpdateChecker;

/**
 * Lightweight progress endpoint polled by the admin page with plain fetch(), instead of Livewire requests that
 * would boot the whole panel while Composer swaps vendor/. Failed polls are simply retried by the browser.
 */
final class StatusController
{
    public function __invoke(Status $status, UpdateChecker $checker): JsonResponse
    {
        abort_unless(Authorization::allows(), 403);

        $current = $status->get();

        return response()->json([
            'state' => $current['state'],
            'target' => $current['version'],
            'step' => $current['step'],
            'message' => $current['message'],
            'log' => array_slice($current['log'], -200),
            'busy' => $status->isBusy() || $checker->isChecking(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
