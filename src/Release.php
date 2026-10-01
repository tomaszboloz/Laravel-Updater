<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Carbon\CarbonImmutable;

/** A published GitHub release whose tag is a semantic version. */
final readonly class Release
{
    /** Optional "v" + semver. Starting with a letter/digit also rules out option injection into git arguments. */
    public const string TAG_PATTERN = '/\A[vV]?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/';

    public function __construct(
        public string $tag,
        public string $name,
        public string $notes = '',
        public ?string $url = null,
        public ?CarbonImmutable $publishedAt = null,
    ) {
        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw UpdaterException::invalidTag($tag);
        }
    }

    /** @param array<mixed> $payload GitHub "release" object */
    public static function fromGitHub(array $payload): self
    {
        $tag = is_string($payload['tag_name'] ?? null) ? $payload['tag_name'] : '';
        $name = $payload['name'] ?? null;
        $url = $payload['html_url'] ?? null;
        $published = $payload['published_at'] ?? null;

        return new self(
            tag: $tag,
            name: is_string($name) && trim($name) !== '' ? $name : $tag,
            notes: is_string($payload['body'] ?? null) ? $payload['body'] : '',
            url: is_string($url) && str_starts_with($url, 'https://') ? $url : null,
            publishedAt: is_string($published) ? CarbonImmutable::parse($published) : null,
        );
    }

    public static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    public function version(): string
    {
        return self::normalize($this->tag);
    }

    public function isNewerThan(string $version): bool
    {
        return version_compare($this->version(), self::normalize($version), '>');
    }
}
