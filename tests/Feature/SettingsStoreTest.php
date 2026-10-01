<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\GitHub;
use TomaszBoloz\LaravelUpdater\SettingsStore;
use TomaszBoloz\LaravelUpdater\Sources\ArchiveSource;
use TomaszBoloz\LaravelUpdater\Sources\Source;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;

final class SettingsStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    }

    public function test_secrets_are_encrypted_at_rest_in_a_private_file(): void
    {
        $this->store()->save(['repository' => 'acme/panel', 'token' => self::TOKEN, 'maintenance_secret' => 'bypass-123']);

        $path = storage_path('app/updater/settings.json');
        $raw = File::get($path);

        $this->assertStringNotContainsString(self::TOKEN, $raw);
        $this->assertStringNotContainsString('bypass-123', $raw);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame(['repository' => 'acme/panel', 'token' => self::TOKEN, 'maintenance_secret' => 'bypass-123'], $this->store()->all());
    }

    public function test_saving_merges_and_empty_values_remove_settings(): void
    {
        $this->store()->save(['repository' => 'acme/panel', 'token' => self::TOKEN, 'strategy' => 'archive']);
        $this->store()->save(['token' => null, 'strategy' => '', 'maintenance_retry' => 30, 'unknown' => 'x']);

        $this->assertSame(['repository' => 'acme/panel', 'maintenance_retry' => 30], $this->store()->all());
    }

    public function test_corrupted_or_undecryptable_values_are_ignored(): void
    {
        File::ensureDirectoryExists(storage_path('app/updater'));
        File::put(storage_path('app/updater/settings.json'), json_encode(['token' => 'not-encrypted', 'repository' => ['x'], 'strategy' => 'git']));
        $this->assertSame(['strategy' => 'git'], $this->store()->all());

        File::put(storage_path('app/updater/settings.json'), '{broken');
        $this->assertSame([], $this->store()->all());
    }

    public function test_panel_settings_take_precedence_over_config(): void
    {
        Config::set('updater.token', 'env-token');
        $this->store()->save(['repository' => 'acme/panel', 'token' => self::TOKEN, 'strategy' => 'archive', 'maintenance_secret' => 'bypass-123']);
        Http::fake(['*' => Http::response($this->release())]);

        $this->app->make(GitHub::class)->latestRelease();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'repos/acme/panel/')
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertInstanceOf(ArchiveSource::class, $this->app->make(Source::class));

        Process::fake(['*' => Process::result(errorOutput: 'leak '.self::TOKEN.' bypass-123', exitCode: 1)]);

        try {
            $this->app->make(CommandRunner::class)->run(['@git', 'status']);
            $this->fail('The failing command must throw.');
        } catch (UpdaterException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringNotContainsString('bypass-123', $exception->getMessage());
        }
    }

    public function test_config_is_used_until_the_panel_saves_a_value(): void
    {
        Http::fake(['*' => Http::response($this->release())]);

        $this->app->make(GitHub::class)->latestRelease();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'repos/acme/shop/'));
    }

    private function store(): SettingsStore
    {
        return $this->app->make(SettingsStore::class);
    }
}
