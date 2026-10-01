<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use TomaszBoloz\LaravelUpdater\GitHub;
use TomaszBoloz\LaravelUpdater\Packages\Package;
use TomaszBoloz\LaravelUpdater\SettingsStore;

/**
 * Private Composer packages (e.g. own plugins) hosted on GitHub, each with its own token. They are monitored by tags,
 * and their tokens are given to Composer and git during updates. Tokens are encrypted and never sent back to the browser.
 */
final class PrivatePackagesAction
{
    public static function make(): Action
    {
        return Action::make('privatePackages')
            ->label(__('updater::updater.private.title'))
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->modalWidth('4xl')
            ->modalDescription(__('updater::updater.private.description'))
            ->modalSubmitActionLabel(__('updater::updater.settings.save'))
            ->fillForm(fn (SettingsStore $store): array => ['packages' => array_map(
                static fn (array $package): array => [...$package, 'token' => null, 'has_token' => $package['token'] !== null],
                $store->packages(),
            )])
            ->schema([
                Repeater::make('packages')
                    ->hiddenLabel()
                    ->addActionLabel(__('updater::updater.private.add'))
                    ->defaultItems(0)
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => is_string($state['name'] ?? null) ? $state['name'] : null)
                    ->schema([
                        Hidden::make('has_token'),
                        TextInput::make('name')
                            ->label(__('updater::updater.private.name'))
                            ->placeholder('vendor/package')
                            ->required()
                            ->distinct()
                            ->regex(Package::NAME_PATTERN),
                        TextInput::make('repository')
                            ->label(__('updater::updater.settings.repository'))
                            ->placeholder('owner/repository')
                            ->required()
                            ->regex(GitHub::REPOSITORY_PATTERN),
                        TextInput::make('token')
                            ->label(__('updater::updater.settings.token'))
                            ->helperText(fn (callable $get): string => __($get('has_token') === true
                                ? 'updater::updater.settings.token_set' : 'updater::updater.private.token_help'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255),
                    ]),
            ])
            ->action(function (array $data, SettingsStore $store): void {
                $saved = array_column($store->packages(), 'token', 'name');
                $packages = [];

                foreach ((array) ($data['packages'] ?? []) as $item) {
                    $name = is_array($item) && is_string($item['name'] ?? null) ? trim($item['name']) : '';
                    $repository = is_array($item) && is_string($item['repository'] ?? null) ? trim($item['repository']) : '';
                    $token = is_array($item) && is_string($item['token'] ?? null) && trim($item['token']) !== '' ? trim($item['token']) : null;

                    if ($name !== '' && $repository !== '') {
                        // An empty token field keeps the token saved for this package.
                        $packages[] = ['name' => $name, 'repository' => $repository, 'token' => $token ?? ($saved[$name] ?? null)];
                    }
                }

                $store->save(['packages' => $packages]);

                Notification::make()->title(__('updater::updater.settings.saved'))->success()->send();
            });
    }
}
