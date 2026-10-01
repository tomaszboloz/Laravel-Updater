<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use TomaszBoloz\LaravelUpdater\Credentials;
use TomaszBoloz\LaravelUpdater\Events\UpdatesAvailable;
use TomaszBoloz\LaravelUpdater\SettingsStore;
use TomaszBoloz\LaravelUpdater\Tests\Fixtures\InstalledApp;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdateChecker;

final class MonitoringTest extends TestCase
{
    private InstalledApp $installed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $this->installed = new InstalledApp(['acme/plugin' => '^1.0'], [], ['acme/plugin' => '1.0.0']);
        $this->app->setBasePath($this->installed->path);
    }

    protected function tearDown(): void
    {
        $this->installed->delete();

        parent::tearDown();
    }

    public function test_one_check_covers_the_application_and_packages_and_announces_updates(): void
    {
        Event::fake();
        $this->app->make(SettingsStore::class)->save(['packages' => [['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token']]]);
        Process::fake(['*outdated*' => Process::result('{"installed": []}')]);
        Http::fake([
            'api.github.com/repos/acme/shop/releases/latest' => Http::response($this->release()),
            'api.github.com/repos/acme/plugin/tags*' => Http::response([['name' => '1.1.0']]),
        ]);
        $checker = $this->app->make(UpdateChecker::class);

        $result = $checker->check();

        $this->assertSame('1.2.0', $result['release']?->version());
        $this->assertSame(['acme/plugin'], array_map(static fn ($package): string => $package->name, $result['packages']));
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $checker->availableCount());
        $this->assertFalse($checker->isChecking());
        Event::assertDispatched(UpdatesAvailable::class, fn (UpdatesAvailable $event): bool => $event->release?->tag === 'v1.2.0' && count($event->packages) === 1);

        // After the package is updated, the badge clears without another check.
        File::put($this->installed->path.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'acme/plugin', 'version' => '1.1.0']]]));
        $this->assertSame(1, $checker->availableCount());
    }

    public function test_the_check_is_scheduled_from_config(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->command, 'updater:check'));

        $this->assertCount(1, $events);
        $this->assertSame('0 */6 * * *', $events->first()->expression);
    }

    public function test_each_repository_gets_its_own_git_credentials_and_composer_gets_a_token(): void
    {
        $this->app['config']->set('updater.token', 'app-token');
        $this->app->make(SettingsStore::class)->save(['packages' => [
            ['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token'],
            ['name' => 'acme/public', 'repository' => 'acme/public', 'token' => null],
        ]]);
        $credentials = $this->app->make(Credentials::class);
        $environment = $credentials->environment();

        $this->assertSame('4', $environment['GIT_CONFIG_COUNT']);
        $this->assertSame('http.https://github.com/acme/shop.extraheader', $environment['GIT_CONFIG_KEY_0']);
        $this->assertSame('AUTHORIZATION: basic '.base64_encode('x-access-token:app-token'), $environment['GIT_CONFIG_VALUE_0']);
        $this->assertSame('http.https://github.com/acme/plugin.git.extraheader', $environment['GIT_CONFIG_KEY_3']);
        $this->assertSame('AUTHORIZATION: basic '.base64_encode('x-access-token:plugin-token'), $environment['GIT_CONFIG_VALUE_3']);
        $this->assertSame('{"github-oauth":{"github.com":"app-token"}}', $environment['COMPOSER_AUTH']);
        $this->assertContains('plugin-token', $credentials->secrets());
        $this->assertContains(base64_encode('x-access-token:app-token'), $credentials->secrets());
    }

    public function test_package_tokens_are_encrypted_at_rest_and_invalid_entries_are_dropped(): void
    {
        $store = $this->app->make(SettingsStore::class);
        $store->save(['packages' => [
            ['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token'],
            ['name' => '--evil', 'repository' => 'acme/x', 'token' => null],
            ['name' => 'acme/x', 'repository' => '../../etc', 'token' => null],
        ]]);

        $this->assertStringNotContainsString('plugin-token', File::get(storage_path('app/updater/settings.json')));
        $this->assertSame([['name' => 'acme/plugin', 'repository' => 'acme/plugin', 'token' => 'plugin-token']], $store->packages());

        $store->save(['packages' => []]);
        $this->assertSame([], $store->packages());
    }
}
