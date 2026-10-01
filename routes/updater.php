<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use TomaszBoloz\LaravelUpdater\Http\StatusController;

// Progress of the running update for the admin page; authorization happens in the controller (gate).
Route::middleware(['web', 'auth'])
    ->get(trim((string) Config::get('updater.route_prefix', 'updater'), '/').'/status', StatusController::class)
    ->name('updater.status');
