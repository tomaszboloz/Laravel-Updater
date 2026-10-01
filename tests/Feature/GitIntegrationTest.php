<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Symfony\Component\Process\ExecutableFinder;
use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Sources\GitSource;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;

/** Runs the git strategy against real repositories: a bare "origin" and a deployed clone. */
final class GitIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new ExecutableFinder)->find('git') === null) {
            $this->markTestSkipped('git is not installed.');
        }

        $this->root = sys_get_temp_dir().'/updater-git-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/work', 0777, true);

        $this->git($this->root, 'init', '--bare', '--quiet', '--initial-branch=main', 'origin.git');
        $this->git($this->root.'/work', 'init', '--quiet');
        $this->commit('1.0.0');
        $this->git($this->root.'/work', 'remote', 'add', 'origin', $this->root.'/origin.git');
        $this->git($this->root.'/work', 'push', '--quiet', 'origin', 'HEAD:refs/heads/main');
        $this->git($this->root, 'clone', '--quiet', $this->root.'/origin.git', 'app');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_checks_out_a_new_tag_and_rolls_back_to_the_snapshot(): void
    {
        $this->commit('1.1.0');
        $this->git($this->root.'/work', 'tag', 'v1.1.0');
        $this->git($this->root.'/work', 'push', '--quiet', 'origin', 'v1.1.0');

        $source = $this->source();
        $snapshot = $source->snapshot();
        $source->apply(new Release('v1.1.0', 'name'));
        $this->assertSame('1.1.0', file_get_contents($this->root.'/app/VERSION'));

        $source->restore($snapshot);
        $this->assertSame('1.0.0', file_get_contents($this->root.'/app/VERSION'));
    }

    public function test_local_changes_block_the_update(): void
    {
        file_put_contents($this->root.'/app/VERSION', 'hotfix');

        $this->expectException(UpdaterException::class);

        $this->source()->snapshot();
    }

    private function source(): GitSource
    {
        $runner = new CommandRunner(new Factory, $this->root.'/app', ['git' => 'git'], ['GIT_CONFIG_NOSYSTEM' => '1'], 60);

        return new GitSource($runner, []);
    }

    private function commit(string $version): void
    {
        file_put_contents($this->root.'/work/VERSION', $version);
        $this->git($this->root.'/work', 'add', 'VERSION');
        $this->git($this->root.'/work', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $version);
    }

    private function git(string $path, string ...$arguments): void
    {
        (new Factory)->path($path)->run(['git', ...$arguments])->throw();
    }
}
