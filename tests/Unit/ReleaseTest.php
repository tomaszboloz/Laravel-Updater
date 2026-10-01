<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\UpdaterException;

final class ReleaseTest extends TestCase
{
    public function test_it_is_built_from_a_github_payload(): void
    {
        $release = Release::fromGitHub([
            'tag_name' => 'v2.1.0',
            'name' => '',
            'body' => 'Notes',
            'html_url' => 'javascript:alert(1)',
            'published_at' => '2026-09-30T10:00:00Z',
        ]);

        $this->assertSame('v2.1.0', $release->tag);
        $this->assertSame('v2.1.0', $release->name, 'An empty name falls back to the tag.');
        $this->assertSame('2.1.0', $release->version());
        $this->assertSame('Notes', $release->notes);
        $this->assertNull($release->url, 'Only https release links are kept.');
        $this->assertSame('2026-09-30', $release->publishedAt?->toDateString());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeTags(): iterable
    {
        yield 'git option injection' => ['--upload-pack=touch /tmp/pwned'];
        yield 'shell metacharacters' => ['v1.0.0;rm -rf /'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'branch name' => ['main'];
        yield 'empty' => [''];
        yield 'partial version' => ['v1.2'];
    }

    #[DataProvider('unsafeTags')]
    public function test_it_rejects_tags_that_are_not_semantic_versions(string $tag): void
    {
        $this->expectException(UpdaterException::class);

        new Release($tag, 'name');
    }

    #[TestWith(['v1.0.0'])]
    #[TestWith(['1.0.0'])]
    #[TestWith(['V10.20.30'])]
    #[TestWith(['v2.0.0-beta.1'])]
    #[TestWith(['v2.0.0+build.5'])]
    public function test_it_accepts_semantic_version_tags(string $tag): void
    {
        $this->assertSame(ltrim($tag, 'vV'), (new Release($tag, 'name'))->version());
    }

    #[TestWith(['v1.10.0', '1.9.0', true])]
    #[TestWith(['1.2.0', 'v1.2.0', false])]
    #[TestWith(['1.2.0', '1.3.0', false])]
    #[TestWith(['2.0.0', '2.0.0-rc.1', true])]
    public function test_it_compares_versions_semantically(string $tag, string $installed, bool $newer): void
    {
        $this->assertSame($newer, (new Release($tag, 'name'))->isNewerThan($installed));
    }
}
