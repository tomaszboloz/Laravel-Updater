<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Config;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\SettingsStore;
use TomaszBoloz\LaravelUpdater\VersionStore;

/**
 * Modal with the repository, token and maintenance settings, saved to SettingsStore (panel values beat config/.env).
 * Update steps stay in config on purpose: letting panel users define commands would allow running arbitrary code.
 */
final class SettingsAction
{
    public static function make(): Action
    {
        return Action::make('settings')
            ->label(__('updater::updater.settings.title'))
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->modalSubmitActionLabel(__('updater::updater.settings.save'))
            ->fillForm(fn (SettingsStore $store): array => self::current($store))
            ->schema([
                Section::make(__('updater::updater.settings.github'))->schema([
                    TextInput::make('repository')
                        ->label(__('updater::updater.settings.repository'))
                        ->placeholder('owner/repository')
                        ->required()
                        ->regex('/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9._-]{1,100}\z/'),
                    TextInput::make('token')
                        ->label(__('updater::updater.settings.token'))
                        ->helperText(fn (SettingsStore $store): string => __(
                            $store->get('token') === null ? 'updater::updater.settings.token_help' : 'updater::updater.settings.token_set',
                        ))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->maxLength(255),
                    Toggle::make('forget_token')->label(__('updater::updater.settings.forget_token')),
                    Select::make('strategy')
                        ->label(__('updater::updater.settings.strategy'))
                        ->options(['git' => 'git', 'archive' => 'archive (zip)'])
                        ->required(),
                    TextInput::make('current_version')
                        ->label(__('updater::updater.settings.current_version'))
                        ->helperText(__('updater::updater.settings.current_version_help'))
                        ->required()
                        ->regex(Release::TAG_PATTERN),
                ]),
                Section::make(__('updater::updater.settings.maintenance'))->schema([
                    Toggle::make('maintenance_enabled')->label(__('updater::updater.settings.maintenance_enabled')),
                    TextInput::make('maintenance_retry')->label(__('updater::updater.settings.maintenance_retry'))->integer()->minValue(1)->maxValue(3600),
                    TextInput::make('maintenance_secret')
                        ->label(__('updater::updater.settings.maintenance_secret'))
                        ->helperText(__('updater::updater.settings.maintenance_secret_help'))
                        ->password()
                        ->revealable()
                        ->alphaDash()
                        ->maxLength(100),
                ]),
            ])
            ->action(function (array $data, SettingsStore $store, VersionStore $versions): void {
                $store->save([
                    'repository' => self::stringOrNull($data['repository'] ?? null),
                    'strategy' => self::stringOrNull($data['strategy'] ?? null),
                    'maintenance_enabled' => (bool) ($data['maintenance_enabled'] ?? true),
                    'maintenance_retry' => is_numeric($data['maintenance_retry'] ?? null) ? (int) $data['maintenance_retry'] : null,
                    'maintenance_secret' => self::stringOrNull($data['maintenance_secret'] ?? null),
                    // An empty token field keeps the stored token; the toggle removes it.
                    ...(($data['forget_token'] ?? false) ? ['token' => null] : array_filter(['token' => self::stringOrNull($data['token'] ?? null)])),
                ]);

                $version = self::stringOrNull($data['current_version'] ?? null);

                if ($version !== null && Release::normalize($version) !== $versions->current()) {
                    $versions->remember(new Release($version, $version));
                }

                Notification::make()->title(__('updater::updater.settings.saved'))->success()->send();
            });
    }

    /** @return array<string, mixed> current values; secrets are never sent back to the browser except the bypass secret */
    private static function current(SettingsStore $store): array
    {
        $setting = static fn (string $key, string $config): mixed => $store->get($key) ?? Config::get('updater.'.$config);

        return [
            'repository' => $setting('repository', 'repository'),
            'token' => null,
            'forget_token' => false,
            'strategy' => $setting('strategy', 'strategy'),
            'current_version' => app(VersionStore::class)->current(),
            'maintenance_enabled' => $setting('maintenance_enabled', 'maintenance.enabled'),
            'maintenance_retry' => $setting('maintenance_retry', 'maintenance.retry'),
            'maintenance_secret' => $setting('maintenance_secret', 'maintenance.secret'),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
