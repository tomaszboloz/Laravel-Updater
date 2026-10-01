<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Settings saved from the admin panel. They take precedence over config/.env values.
 * Stored as JSON in storage (outside git and preserved by the archive strategy); secrets are encrypted with APP_KEY.
 *
 * @phpstan-type Settings array{repository?: string, token?: string, strategy?: string, maintenance_enabled?: bool, maintenance_retry?: int, maintenance_secret?: string}
 */
final readonly class SettingsStore
{
    private const array SECRETS = ['token', 'maintenance_secret'];

    private const array TYPES = [
        'repository' => 'string', 'token' => 'string', 'strategy' => 'string',
        'maintenance_enabled' => 'bool', 'maintenance_retry' => 'int', 'maintenance_secret' => 'string',
    ];

    /** @param Closure(): Encrypter $encrypter resolved lazily, so apps without stored secrets need no APP_KEY */
    public function __construct(private Filesystem $files, private Closure $encrypter, private string $path) {}

    /** @return Settings decrypted values; unknown, mistyped or undecryptable entries are dropped */
    public function all(): array
    {
        try {
            $stored = $this->files->exists($this->path) ? json_decode($this->files->get($this->path), true, 4, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            return [];
        }

        $settings = [];

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_string($key) && in_array($key, self::SECRETS, true) && is_string($value)) {
                $value = $this->decrypt($value);
            }

            if (is_string($key) && isset(self::TYPES[$key]) && get_debug_type($value) === self::TYPES[$key]) {
                $settings[$key] = $value;
            }
        }

        /** @var Settings $settings */
        return $settings;
    }

    public function get(string $key): string|int|bool|null
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, string|int|bool|null> $values null or "" removes a setting */
    public function save(array $values): void
    {
        $settings = $this->all();

        foreach (array_intersect_key($values, self::TYPES) as $key => $value) {
            if ($value === null || $value === '') {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }
        }

        foreach (self::SECRETS as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = ($this->encrypter)()->encrypt($settings[$key], false);
            }
        }

        $this->files->ensureDirectoryExists(dirname($this->path), 0700);
        $this->files->replace($this->path, json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 0600);
    }

    private function decrypt(string $value): ?string
    {
        try {
            $decrypted = ($this->encrypter)()->decrypt($value, false);

            return is_string($decrypted) ? $decrypted : null;
        } catch (DecryptException) {
            return null; // e.g. APP_KEY rotated: the secret has to be entered again
        }
    }
}
