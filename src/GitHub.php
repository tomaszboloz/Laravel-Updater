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
    public const string REPOSITORY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9._-]{1,100}\z/';

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

    /** Client for another repository, e.g. a private Composer package with its own token. */
    public function forRepository(string $repository, #[SensitiveParameter] ?string $token): self
    {
        return new self($this->http, $this->cache, $repository, $token, $this->apiUrl, $this->cacheMinutes);
    }

    /** Highest semantic-version tag (packages often publish tags without releases). */
    public function latestTag(bool $fresh = false): ?string
    {
        $key = 'updater:tag:'.sha1($this->repository());

        if ($fresh) {
            $this->cache->forget($key);
        }

        $tag = $this->cache->remember($key, now()->addMinutes($this->cacheMinutes), function (): string {
            $response = $this->request()->get("repos/{$this->repository()}/tags", ['per_page' => 100]);

            if ($response->failed()) {
                throw UpdaterException::github($response->status());
            }

            $tags = array_filter(
                array_column((array) $response->json(), 'name'),
                static fn (mixed $name): bool => is_string($name) && preg_match(Release::TAG_PATTERN, $name) === 1,
            );
            usort($tags, static fn (string $a, string $b): int => version_compare(Release::normalize($b), Release::normalize($a)));

            return $tags[0] ?? '';
        });

        return $tag !== '' ? $tag : null;
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
