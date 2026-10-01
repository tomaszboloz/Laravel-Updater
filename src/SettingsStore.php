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
 * @phpstan-type StoredSettings array{repository?: string, token?: string, strategy?: string, maintenance_enabled?: bool, maintenance_retry?: int, maintenance_secret?: string, packages?: list<array{name: string, repository: string, token: string|null}>}
 */
final readonly class SettingsStore
{
    private const array SECRETS = ['token', 'maintenance_secret'];

    private const array TYPES = [
        'repository' => 'string', 'token' => 'string', 'strategy' => 'string',
        'maintenance_enabled' => 'bool', 'maintenance_retry' => 'int', 'maintenance_secret' => 'string',
        'packages' => 'array',
    ];

    /** @param Closure(): Encrypter $encrypter resolved lazily, so apps without stored secrets need no APP_KEY */
    public function __construct(private Filesystem $files, private Closure $encrypter, private string $path) {}

    /** @return StoredSettings decrypted values; unknown, mistyped or undecryptable entries are dropped */
    public function all(): array
    {
        try {
            $stored = $this->files->exists($this->path) ? json_decode($this->files->get($this->path), true, 8, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            return [];
        }

        $settings = [];

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_string($key) && in_array($key, self::SECRETS, true) && is_string($value)) {
                $value = $this->decrypt($value);
            }

            if ($key === 'packages') {
                $value = $this->packageList(is_array($value) ? $value : [], decrypt: true);
            }

            if (is_string($key) && isset(self::TYPES[$key]) && get_debug_type($value) === self::TYPES[$key]) {
                $settings[$key] = $value;
            }
        }

        /** @var StoredSettings $settings */
        return $settings;
    }

    public function get(string $key): string|int|bool|null
    {
        $value = $this->all()[$key] ?? null;

        return is_array($value) ? null : $value;
    }

    /** @return list<array{name: string, repository: string, token: string|null}> */
    public function packages(): array
    {
        return $this->all()['packages'] ?? [];
    }

    /** @param array<string, mixed> $values null or "" removes a setting; "packages" replaces the whole list */
    public function save(array $values): void
    {
        $settings = $this->all();

        foreach (array_intersect_key($values, self::TYPES) as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($settings[$key]);
            } elseif (is_scalar($value) || is_array($value)) {
                $settings[$key] = $value;
            }
        }

        foreach (self::SECRETS as $key) {
            if (isset($settings[$key]) && is_string($settings[$key])) {
                $settings[$key] = ($this->encrypter)()->encrypt($settings[$key], false);
            }
        }

        if (isset($settings['packages'])) {
            $settings['packages'] = $this->packageList((array) $settings['packages'], decrypt: false);
        }

        $this->files->ensureDirectoryExists(dirname($this->path), 0700);
        $this->files->replace($this->path, json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 0600);
    }

    /**
     * Valid entries only; tokens are decrypted when reading and encrypted when writing.
     *
     * @param  array<mixed>  $items
     * @return list<array{name: string, repository: string, token: string|null}>
     */
    private function packageList(array $items, bool $decrypt): array
    {
        $packages = [];

        foreach ($items as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;
            $repository = is_array($item) ? ($item['repository'] ?? null) : null;
            $token = is_array($item) && is_string($item['token'] ?? null) && $item['token'] !== '' ? $item['token'] : null;

            if (is_string($name) && preg_match(Packages\Package::NAME_PATTERN, $name) === 1
                && is_string($repository) && preg_match(GitHub::REPOSITORY_PATTERN, $repository) === 1) {
                $token = $token === null ? null : ($decrypt ? $this->decrypt($token) : ($this->encrypter)()->encrypt($token, false));
                $packages[] = ['name' => $name, 'repository' => $repository, 'token' => $token];
            }
        }

        return $packages;
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
