<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use BezhanSalleh\FilamentShield\FilamentShield;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPage;

/**
 * Who may open the Updates page, poll its progress and start updates. With Filament Shield installed, the page's
 * Shield permission decides (granted per role in Shield's role editor); without it, the "updater.ability" gate.
 */
final class Authorization
{
    public static function allows(): bool
    {
        $ability = self::shieldPermission() ?? Config::get('updater.ability', 'updater.manage');

        return is_string($ability) && $ability !== '' && Gate::allows($ability);
    }

    /** The page's Shield permission, e.g. "View:UpdaterPage"; null without Shield or when Shield excludes the page. */
    public static function shieldPermission(): ?string
    {
        if (! Config::boolean('updater.shield', true) || ! app()->bound('filament-shield')
            || in_array(UpdaterPage::class, Config::array('filament-shield.pages.exclude', []), true)) {
            return null;
        }

        $shield = app('filament-shield');
        $keys = $shield instanceof FilamentShield
            ? $shield->getDefaultPermissionKeys(UpdaterPage::class, Config::string('filament-shield.pages.prefix', 'view'))
            : [];
        $key = array_key_first($keys);

        return is_string($key) ? $key : null;
    }
}
