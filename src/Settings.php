<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Config\Repository as Config;

/** Effective settings: values saved in the admin panel first, then config/updater.php (and .env). */
final readonly class Settings
{
    public function __construct(private SettingsStore $store, private Config $config) {}

    public function repository(): ?string
    {
        return $this->text('repository');
    }

    public function token(): ?string
    {
        return $this->text('token');
    }

    public function strategy(): string
    {
        return $this->text('strategy') ?? 'git';
    }

    public function apiUrl(): string
    {
        return $this->configString('api_url') ?? 'https://api.github.com';
    }

    /** @return array{enabled: bool, retry: int, secret: string|null} */
    public function maintenance(): array
    {
        $enabled = $this->store->get('maintenance_enabled');
        $retry = $this->store->get('maintenance_retry');

        return [
            'enabled' => is_bool($enabled) ? $enabled : $this->config->boolean('updater.maintenance.enabled', true),
            'retry' => is_int($retry) ? $retry : $this->config->integer('updater.maintenance.retry', 60),
            'secret' => $this->text('maintenance_secret', 'maintenance.secret'),
        ];
    }

    /** @return list<array{name: string, repository: string, token: string|null}> monitored private packages */
    public function packages(): array
    {
        return $this->store->packages();
    }

    /** @return list<list<string>> */
    public function commands(string $key): array
    {
        $commands = [];

        foreach ($this->config->array('updater.'.$key, []) as $command) {
            if (is_array($command) && $command !== [] && array_is_list($command) && array_filter($command, 'is_string') === $command) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /** @return array<string, string> */
    public function map(string $key): array
    {
        $map = [];

        foreach ($this->config->array('updater.'.$key, []) as $name => $value) {
            if (is_string($name) && is_string($value) && $value !== '') {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        return array_values(array_filter($this->config->array('updater.'.$key, []), 'is_string'));
    }

    public function integer(string $key, int $default): int
    {
        return $this->config->integer('updater.'.$key, $default);
    }

    public function configString(string $key): ?string
    {
        $value = $this->config->get('updater.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function text(string $setting, ?string $configKey = null): ?string
    {
        $value = $this->store->get($setting);

        return is_string($value) && $value !== '' ? $value : $this->configString($configKey ?? $setting);
    }
}
