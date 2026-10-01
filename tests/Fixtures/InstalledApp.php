<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Fixtures;

use Illuminate\Filesystem\Filesystem;

/** A temporary application root with composer.json and vendor/composer/installed.json. */
final class InstalledApp
{
    public readonly string $path;

    /** @param array<string, string> $installed name => version */
    public function __construct(array $require, array $requireDev = [], array $installed = [])
    {
        $this->path = sys_get_temp_dir().'/updater-app-'.bin2hex(random_bytes(6));
        mkdir($this->path.'/vendor/composer', 0777, true);
        // "autoload" lets Laravel detect the application namespace, which Blade needs when compiling views.
        file_put_contents($this->path.'/composer.json', json_encode(['require' => $require, 'require-dev' => $requireDev, 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
        file_put_contents($this->path.'/composer.lock', '{"original": true}');
        file_put_contents($this->path.'/vendor/composer/installed.json', json_encode(['packages' => array_map(
            static fn (string $name, string $version): array => ['name' => $name, 'version' => $version],
            array_keys($installed),
            $installed,
        )]));
    }

    public function delete(): void
    {
        (new Filesystem)->deleteDirectory($this->path);
    }
}
