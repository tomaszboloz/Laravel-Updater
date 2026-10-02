<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Process\Factory as Process;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TomaszBoloz\LaravelUpdater\Concerns\RegistersPackageServices;
use TomaszBoloz\LaravelUpdater\Console\CheckCommand;
use TomaszBoloz\LaravelUpdater\Console\PackagesCommand;
use TomaszBoloz\LaravelUpdater\Console\RunCommand;
use TomaszBoloz\LaravelUpdater\Console\WorkCommand;
use TomaszBoloz\LaravelUpdater\Sources\ArchiveSource;
use TomaszBoloz\LaravelUpdater\Sources\GitSource;
use TomaszBoloz\LaravelUpdater\Sources\Source;
use TomaszBoloz\LaravelUpdater\State\RunLock;
use TomaszBoloz\LaravelUpdater\State\StateStore;

final class UpdaterServiceProvider extends PackageServiceProvider
{
    use RegistersPackageServices;

    public static string $name = 'laravel-updater';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasRoute('updater')
            ->hasCommands([CheckCommand::class, RunCommand::class, PackagesCommand::class, WorkCommand::class]);
    }

    /** Settings-dependent services are bound (not shared), so long-running queue workers see panel changes. */
    public function packageRegistered(): void
    {
        $this->app->singleton(SettingsStore::class, fn (Application $app): SettingsStore => new SettingsStore(
            $app->make(Filesystem::class),
            static fn (): Encrypter => $app->make(Encrypter::class),
            $app->storagePath('app/updater/settings.json'),
        ));

        $this->app->singleton(StateStore::class, fn (Application $app): StateStore => new StateStore($app->make(Filesystem::class), $app->storagePath('app/updater')));
        $this->app->singleton(RunLock::class, fn (Application $app): RunLock => new RunLock($app->storagePath('app/updater/run.lock')));
        $this->app->bind(Settings::class, fn (Application $app): Settings => new Settings($app->make(SettingsStore::class), $app->make('config'), $app->make(StateStore::class)));
        $this->app->bind(Credentials::class, fn (Application $app): Credentials => new Credentials($app->make(Settings::class)));

        $this->app->bind(GitHub::class, fn (Application $app): GitHub => new GitHub(
            $app->make(Http::class),
            $this->cache($app),
            $this->settings($app)->repository(),
            $this->settings($app)->token(),
            $this->settings($app)->apiUrl(),
            $this->settings($app)->integer('check_cache_minutes', 10),
        ));

        $this->app->bind(CommandRunner::class, fn (Application $app): CommandRunner => new CommandRunner(
            $app->make(Process::class),
            $app->basePath(),
            $this->settings($app)->map('binaries'),
            $this->settings($app)->map('environment'),
            $this->settings($app)->integer('timeout', 900),
            [...$app->make(Credentials::class)->secrets(), ...array_filter([$this->settings($app)->maintenance()['secret']])],
            $app->make(Credentials::class)->environment(),
            $this->hasDevPackages($app),
        ));

        $this->app->bind(Status::class, fn (Application $app): Status => new Status($app->make(StateStore::class), $app->make(RunLock::class)));

        $this->app->bind(VersionStore::class, fn (Application $app): VersionStore => new VersionStore(
            $app->make(Filesystem::class),
            $app->storagePath('app/updater/version'),
            $this->settings($app)->configString('current_version') ?? '0.0.0',
        ));

        $this->app->bind(Source::class, fn (Application $app): Source => match ($strategy = $this->settings($app)->strategy()) {
            'git' => new GitSource($app->make(CommandRunner::class), $this->settings($app)->list('git_ignored_changes')),
            'archive' => new ArchiveSource(
                $app->make(GitHub::class),
                $app->make(Filesystem::class),
                $app->basePath(),
                $app->storagePath('app/updater/work'),
                $app->storagePath('app/updater/archive-manifest.json'),
                $this->settings($app)->list('preserve'),
            ),
            default => throw UpdaterException::unknownStrategy($strategy),
        });

        $this->app->bind(Pipeline::class, fn (Application $app): Pipeline => new Pipeline(
            $app->make(CommandRunner::class),
            $app->make(Status::class),
            $app->make(RunLock::class),
            $this->settings($app)->maintenance(),
        ));

        $this->app->bind(Background::class, fn (Application $app): Background => new Background(
            $app->make(Process::class),
            $app->make(BusDispatcher::class),
            $app->make(Status::class),
            $this->settings($app),
            $app->basePath(),
            $app->storagePath('logs/updater.log'),
            $this->settings($app)->configString('runner') ?? 'process',
        ));

        $this->app->bind(Updater::class, fn (Application $app): Updater => new Updater(
            $app->make(GitHub::class),
            $app->make(Source::class),
            $app->make(Pipeline::class),
            $app->make(VersionStore::class),
            $app->make('events'),
            $this->settings($app)->commands('steps'),
            $this->settings($app)->commands('recovery_steps'),
        ));

        $this->registerPackageServices();
    }

    /** Composer records whether the last install included require-dev packages. */
    private function hasDevPackages(Application $app): bool
    {
        $installed = $app->basePath('vendor/composer/installed.json');
        $data = is_file($installed) ? json_decode((string) file_get_contents($installed), true) : null;

        return is_array($data) && ($data['dev'] ?? false) === true;
    }

    private function settings(Application $app): Settings
    {
        return $app->make(Settings::class);
    }

    private function cache(Application $app): Cache
    {
        return $app->make(CacheFactory::class)->store($this->settings($app)->configString('cache_store'));
    }
}
