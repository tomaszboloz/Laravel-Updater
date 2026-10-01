<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
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
    /** Package name; the short name "updater" is the config file, view and translation namespace. */
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

    public function packageRegistered(): void
    {
        $this->app->singleton(GitHub::class, fn (Application $app): GitHub => new GitHub(
            $app->make(Http::class),
            $this->cache($app),
            $this->string($app, 'updater.repository'),
            $this->string($app, 'updater.token'),
            $this->string($app, 'updater.api_url') ?? 'https://api.github.com',
            $this->config($app)->integer('updater.check_cache_minutes', 10),
        ));

        $this->app->singleton(CommandRunner::class, fn (Application $app): CommandRunner => new CommandRunner(
            $app->make(Process::class),
            $app->basePath(),
            $this->strings($app, 'updater.binaries'),
            $this->strings($app, 'updater.environment'),
            $this->config($app)->integer('updater.timeout', 900),
            [...$app->make(GitHub::class)->secrets(), ...array_filter([$this->string($app, 'updater.maintenance.secret')])],
        ));

        $this->app->singleton(Status::class, fn (Application $app): Status => new Status(
            $this->cache($app),
            $this->config($app)->integer('updater.queue.timeout', 3600),
        ));

        $this->app->singleton(VersionStore::class, fn (Application $app): VersionStore => new VersionStore(
            $app->make(Filesystem::class),
            $app->storagePath('app/updater/version'),
            $this->string($app, 'updater.current_version') ?? '0.0.0',
        ));

        $this->app->singleton(Source::class, fn (Application $app): Source => match ($strategy = $this->string($app, 'updater.strategy')) {
            'git' => new GitSource($app->make(CommandRunner::class), $app->make(GitHub::class)),
            'archive' => new ArchiveSource(
                $app->make(GitHub::class),
                $app->make(Filesystem::class),
                $app->basePath(),
                $app->storagePath('app/updater/work'),
                array_values(array_filter((array) $this->config($app)->get('updater.preserve', []), 'is_string')),
            ),
            default => throw UpdaterException::unknownStrategy((string) $strategy),
        });

        $this->app->singleton(Updater::class, function (Application $app): Updater {
            $locks = $this->cache($app)->getStore();

            if (! $locks instanceof LockProvider) {
                throw new LogicException('The updater cache store must support atomic locks.');
            }

            /** @var array{steps: list<list<string>>, recovery_steps: list<list<string>>, maintenance: array{enabled?: bool, retry?: int, secret?: string|null}} $config */
            $config = $this->config($app)->get('updater');

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
                $config['maintenance'],
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

    private function string(Application $app, string $key): ?string
    {
        $value = $this->config($app)->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
