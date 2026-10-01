<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPlugin;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(UpdaterPlugin::make()->navigationGroup('System')->navigationSort(5));
    }
}
