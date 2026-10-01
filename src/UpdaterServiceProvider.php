<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Process\Factory as Process;
use LogicException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TomaszBoloz\LaravelUpdater\Console\CheckCommand;
use TomaszBoloz\LaravelUpdater\Console\RunCommand;
use TomaszBoloz\LaravelUpdater\Sources\ArchiveSource;
use TomaszBoloz\LaravelUpdater\Sources\GitSource;
use TomaszBoloz\LaravelUpdater\Sources\Source;

final class UpdaterServiceProvider extends PackageServiceProvider
{
    public static string $name = 'laravel-updater';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasCommands([CheckCommand::class, RunCommand::class]);
    }

    /** Settings-dependent services are bound (not shared), so long-running queue workers see panel changes. */
    public function packageRegistered(): void
    {
        $this->app->singleton(SettingsStore::class, fn (Application $app): SettingsStore => new SettingsStore(
            $app->make(Filesystem::class),
            static fn (): Encrypter => $app->make(Encrypter::class),
            $app->storagePath('app/updater/settings.json'),
        ));

        $this->app->bind(GitHub::class, fn (Application $app): GitHub => new GitHub(
            $app->make(Http::class),
            $this->cache($app),
            $this->text($app, 'repository'),
            $this->text($app, 'token'),
            $this->string($app, 'updater.api_url') ?? 'https://api.github.com',
            $this->config($app)->integer('updater.check_cache_minutes', 10),
        ));

        $this->app->bind(CommandRunner::class, fn (Application $app): CommandRunner => new CommandRunner(
            $app->make(Process::class),
            $app->basePath(),
            $this->strings($app, 'updater.binaries'),
            $this->strings($app, 'updater.environment'),
            $this->config($app)->integer('updater.timeout', 900),
            [...$app->make(GitHub::class)->secrets(), ...array_filter([$this->text($app, 'maintenance_secret', 'maintenance.secret')])],
        ));

        $this->app->singleton(Status::class, fn (Application $app): Status => new Status(
            $this->cache($app),
            $this->config($app)->integer('updater.queue.timeout', 3600),
        ));

        $this->app->bind(VersionStore::class, fn (Application $app): VersionStore => new VersionStore(
            $app->make(Filesystem::class),
            $app->storagePath('app/updater/version'),
            $this->string($app, 'updater.current_version') ?? '0.0.0',
        ));

        $this->app->bind(Source::class, fn (Application $app): Source => match ($strategy = $this->text($app, 'strategy')) {
            'git' => new GitSource($app->make(CommandRunner::class), $app->make(GitHub::class)),
            'archive' => new ArchiveSource(
                $app->make(GitHub::class),
                $app->make(Filesystem::class),
                $app->basePath(),
                $app->storagePath('app/updater/work'),
                array_values(array_filter($this->config($app)->array('updater.preserve', []), 'is_string')),
            ),
            default => throw UpdaterException::unknownStrategy((string) $strategy),
        });

        $this->app->bind(Updater::class, function (Application $app): Updater {
            $locks = $this->cache($app)->getStore();

            if (! $locks instanceof LockProvider) {
                throw new LogicException('The updater cache store must support atomic locks.');
            }

            /** @var array{steps: list<list<string>>, recovery_steps: list<list<string>>} $config */
            $config = $this->config($app)->get('updater');
            $stored = $app->make(SettingsStore::class);

            return new Updater(
                $app->make(GitHub::class),
                $app->make(Source::class),
                $app->make(CommandRunner::class),
                $app->make(VersionStore::class),
                $app->make(Status::class),
                $locks,
                $app->make('events'),
                $config['steps'],
                $config['recovery_steps'],
                [
                    'enabled' => (bool) ($stored->get('maintenance_enabled') ?? $this->config($app)->boolean('updater.maintenance.enabled', true)),
                    'retry' => (int) ($stored->get('maintenance_retry') ?? $this->config($app)->integer('updater.maintenance.retry', 60)),
                    'secret' => $this->text($app, 'maintenance_secret', 'maintenance.secret'),
                ],
                $this->config($app)->integer('updater.queue.timeout', 3600) + 300,
            );
        });
    }

    private function config(Application $app): Config
    {
        return $app->make('config');
    }

    private function cache(Application $app): Cache
    {
        return $app->make(CacheFactory::class)->store($this->string($app, 'updater.cache_store'));
    }

    /** @return array<string, string> */
    private function strings(Application $app, string $key): array
    {
        return array_filter($this->config($app)->array($key, []), static fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value) && $value !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** Panel setting, falling back to config("updater.{$configKey}"). */
    private function text(Application $app, string $setting, ?string $configKey = null): ?string
    {
        $value = $app->make(SettingsStore::class)->get($setting);

        return is_string($value) && $value !== '' ? $value : $this->string($app, 'updater.'.($configKey ?? $setting));
    }

    private function string(Application $app, string $key): ?string
    {
        $value = $this->config($app)->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
