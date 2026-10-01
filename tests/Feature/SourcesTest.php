<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use TomaszBoloz\LaravelUpdater\Release;
use TomaszBoloz\LaravelUpdater\Sources\ArchiveSource;
use TomaszBoloz\LaravelUpdater\Sources\GitSource;
use TomaszBoloz\LaravelUpdater\Sources\Source;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;
use ZipArchive;

final class SourcesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/updater-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/storage', 0777, true);
        file_put_contents($this->root.'/.env', 'APP_KEY=keep-me');
        file_put_contents($this->root.'/app.php', 'old');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_git_fetches_and_checks_out_the_tag_with_the_token_kept_out_of_arguments(): void
    {
        Config::set('updater.token', self::TOKEN);
        Process::fake(['*rev-parse*' => Process::result(str_repeat('a', 40)."\n"), '*' => Process::result()]);
        $source = $this->app->make(Source::class);

        $this->assertInstanceOf(GitSource::class, $source);
        $this->assertSame(str_repeat('a', 40), $source->snapshot());
        $source->apply(new Release('v1.2.0', 'name'));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', 'fetch', '--force', '--no-tags', 'origin', '+refs/tags/v1.2.0:refs/tags/v1.2.0']
            && str_contains((string) $process->environment['GIT_CONFIG_VALUE_0'], base64_encode('x-access-token:'.self::TOKEN)));
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', '-c', 'advice.detachedHead=false', 'checkout', '--force', 'refs/tags/v1.2.0']);
        Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), self::TOKEN));
    }

    public function test_git_refuses_to_overwrite_local_changes(): void
    {
        Process::fake(['*status*' => Process::result(" M app/Models/User.php\n")]);

        $this->expectExceptionMessage('local changes');

        $this->app->make(Source::class)->snapshot();
    }

    public function test_git_only_restores_commit_hashes(): void
    {
        Process::fake();

        $this->expectException(UpdaterException::class);

        $this->app->make(Source::class)->restore('--orphan=evil');
    }

    public function test_archive_copies_the_release_but_preserves_protected_paths(): void
    {
        $this->fakeZipball(['shop-abc/app.php' => 'new', 'shop-abc/routes/web.php' => 'routes', 'shop-abc/.env' => 'APP_KEY=stolen', 'shop-abc/storage/x' => 'x']);

        $this->archiveSource()->apply(new Release('v1.2.0', 'name'));

        $this->assertSame('new', file_get_contents($this->root.'/app.php'));
        $this->assertSame('routes', file_get_contents($this->root.'/routes/web.php'));
        $this->assertSame('APP_KEY=keep-me', file_get_contents($this->root.'/.env'));
        $this->assertFileDoesNotExist($this->root.'/storage/x');
        $this->assertSame([], glob($this->root.'/storage/app/updater/work/*') ?: [], 'The work directory is cleaned up.');
    }

    public function test_archive_rejects_zip_slip_entries(): void
    {
        $this->fakeZipball(['shop-abc/app.php' => 'new', 'shop-abc/../../evil.php' => '<?php']);

        try {
            $this->archiveSource()->apply(new Release('v1.2.0', 'name'));
            $this->fail('A traversal entry must abort the update.');
        } catch (UpdaterException $exception) {
            $this->assertStringContainsString('unsafe path', $exception->getMessage());
        }

        $this->assertSame('old', file_get_contents($this->root.'/app.php'), 'Nothing is copied from an unsafe archive.');
    }

    private function archiveSource(): ArchiveSource
    {
        Config::set('updater.strategy', 'archive');
        $this->app->forgetInstance(Source::class);
        $this->app->setBasePath($this->root);

        $source = $this->app->make(Source::class);
        $this->assertInstanceOf(ArchiveSource::class, $source);

        return $source;
    }

    /** @param array<string, string> $entries */
    private function fakeZipball(array $entries): void
    {
        $path = $this->root.'/fixture.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        Http::fake(['*/zipball/*' => Http::response((string) file_get_contents($path))]);
        unlink($path);
    }
}
