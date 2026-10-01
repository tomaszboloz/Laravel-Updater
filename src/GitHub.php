<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;
use Throwable;

/** GitHub REST client for public and private (token) repositories. */
final readonly class GitHub
{
    private const string REPOSITORY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9._-]{1,100}\z/';

    public function __construct(
        private Http $http,
        private Cache $cache,
        private ?string $repository,
        #[SensitiveParameter] private ?string $token = null,
        private string $apiUrl = 'https://api.github.com',
        private int $cacheMinutes = 10,
    ) {}

    public function isConfigured(): bool
    {
        return is_string($this->repository)
            && preg_match(self::REPOSITORY_PATTERN, $this->repository) === 1
            && str_starts_with($this->apiUrl, 'https://');
    }

    /** Latest published (non-draft, non-prerelease) release, or null when the repository has none. */
    public function latestRelease(bool $fresh = false): ?Release
    {
        $key = 'updater:release:'.sha1($this->repository());

        if ($fresh) {
            $this->cache->forget($key);
        }

        // "false" caches the "no releases yet" answer as well.
        $payload = $this->cache->remember($key, now()->addMinutes($this->cacheMinutes), function (): array|false {
            $response = $this->request()->get("repos/{$this->repository()}/releases/latest");

            if ($response->notFound()) {
                return false;
            }

            if ($response->failed()) {
                throw UpdaterException::github($response->status());
            }

            return (array) $response->json();
        });

        return is_array($payload) ? Release::fromGitHub($payload) : null;
    }

    /** Streams the release source zipball to $destination. */
    public function downloadArchive(Release $release, string $destination): void
    {
        $response = $this->request()
            ->timeout(300)
            ->sink($destination)
            ->get("repos/{$this->repository()}/zipball/".rawurlencode($release->tag));

        if ($response->failed()) {
            throw UpdaterException::github($response->status());
        }
    }

    /**
     * Git configuration passed through the environment, so the token never shows up in process arguments.
     *
     * @return array<string, string>
     */
    public function gitEnvironment(): array
    {
        if (! $this->hasToken()) {
            return [];
        }

        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.$this->basicCredentials(),
        ];
    }

    /** @return list<string> values that must never appear in logs */
    public function secrets(): array
    {
        return $this->hasToken() ? [(string) $this->token, $this->basicCredentials()] : [];
    }

    private function repository(): string
    {
        if (! $this->isConfigured()) {
            throw UpdaterException::notConfigured();
        }

        return (string) $this->repository;
    }

    private function hasToken(): bool
    {
        return is_string($this->token) && $this->token !== '';
    }

    private function basicCredentials(): string
    {
        return base64_encode('x-access-token:'.$this->token);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->apiUrl, '/'))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('tomaszboloz/laravel-updater')
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(2, 250, static fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
            ->when($this->hasToken(), fn (PendingRequest $request): PendingRequest => $request->withToken((string) $this->token));
    }
}
