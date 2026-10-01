<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use TomaszBoloz\LaravelUpdater\GitHub;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;

final class GitHubTest extends TestCase
{
    public function test_public_repositories_are_queried_without_credentials(): void
    {
        Http::fake(['api.github.com/repos/acme/shop/releases/latest' => Http::response($this->release())]);

        $release = $this->app->make(GitHub::class)->latestRelease();

        $this->assertSame('v1.2.0', $release?->tag);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization')
            && $request->hasHeader('Accept', 'application/vnd.github+json')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
    }

    public function test_private_repositories_use_the_token(): void
    {
        Config::set('updater.token', self::TOKEN);
        Http::fake(['*' => Http::response($this->release())]);

        $this->app->make(GitHub::class)->latestRelease();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_latest_release_is_cached_until_a_fresh_check(): void
    {
        Http::fake(['*' => Http::response($this->release())]);
        $github = $this->app->make(GitHub::class);

        $github->latestRelease();
        $github->latestRelease();
        Http::assertSentCount(1);

        $github->latestRelease(fresh: true);
        Http::assertSentCount(2);
    }

    public function test_a_repository_without_releases_has_no_latest_release(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertNull($this->app->make(GitHub::class)->latestRelease());
    }

    public function test_rejected_credentials_give_an_actionable_error(): void
    {
        Config::set('updater.token', self::TOKEN);
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->expectExceptionMessage('Check UPDATER_GITHUB_TOKEN');

        $this->app->make(GitHub::class)->latestRelease();
    }

    public function test_invalid_repository_names_are_never_requested(): void
    {
        Config::set('updater.repository', '../../orgs/acme');
        Http::fake();

        try {
            $this->app->make(GitHub::class)->latestRelease();
            $this->fail('An invalid repository must be rejected.');
        } catch (UpdaterException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_plain_http_api_urls_are_rejected(): void
    {
        Config::set('updater.api_url', 'http://github.example.com/api/v3');

        $this->assertFalse($this->app->make(GitHub::class)->isConfigured());
    }

    public function test_archives_are_streamed_from_the_zipball_endpoint(): void
    {
        Config::set('updater.token', self::TOKEN);
        Http::fake(['*/zipball/*' => Http::response('zip-bytes')]);
        $path = tempnam(sys_get_temp_dir(), 'updater');

        $this->app->make(GitHub::class)->downloadArchive(new Release('v1.2.0', 'name'), (string) $path);

        $this->assertSame('zip-bytes', file_get_contents((string) $path));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/shop/zipball/v1.2.0'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        unlink((string) $path);
    }

    public function test_git_credentials_travel_in_the_environment_and_are_listed_as_secrets(): void
    {
        $this->assertSame([], $this->app->make(GitHub::class)->gitEnvironment());

        Config::set('updater.token', self::TOKEN);
        $this->app->forgetInstance(GitHub::class);
        $github = $this->app->make(GitHub::class);
        $basic = base64_encode('x-access-token:'.self::TOKEN);

        $this->assertSame('AUTHORIZATION: basic '.$basic, $github->gitEnvironment()['GIT_CONFIG_VALUE_0']);
        $this->assertSame([self::TOKEN, $basic], $github->secrets());
    }
}
