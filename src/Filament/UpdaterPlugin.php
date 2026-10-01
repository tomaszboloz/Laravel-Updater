<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use LogicException;
use UnitEnum;

/**
 * Panel plugin that adds the "Updates" page:
 * ->plugin(UpdaterPlugin::make()->navigationGroup('Settings')->navigationSort(90))
 */
class UpdaterPlugin implements Plugin
{
    public const string ID = 'laravel-updater';

    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    /** Resolved from the container, so the plugin can be swapped for another implementation. */
    public static function make(): static
    {
        $plugin = app()->make(static::class);

        return $plugin instanceof static ? $plugin : throw new LogicException(sprintf('The container did not resolve %s.', static::class));
    }

    /** The instance registered on the current panel. */
    public static function get(): static
    {
        $plugin = filament(static::ID);

        return $plugin instanceof static ? $plugin : throw new LogicException('The updater plugin is not registered on the current panel.');
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([UpdaterPage::class]);
    }

    public function boot(Panel $panel): void {}
}
