<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use TomaszBoloz\LaravelUpdater\CommandRunner;
use TomaszBoloz\LaravelUpdater\Tests\TestCase;
use TomaszBoloz\LaravelUpdater\UpdaterException;

final class CommandRunnerTest extends TestCase
{
    public function test_placeholders_resolve_to_configured_binaries_and_run_in_the_app_root(): void
    {
        Config::set('updater.binaries.composer', '/usr/bin/php8.3 /usr/local/bin/composer');
        Config::set('updater.environment', ['COMPOSER_HOME' => '/var/composer', 'HOME' => null]);
        Config::set('updater.timeout', 120);
        Process::fake();

        $this->app->make(CommandRunner::class)->run(['@composer', 'install', '--no-dev']);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['/usr/bin/php8.3', '/usr/local/bin/composer', 'install', '--no-dev']
            && $process->path === base_path()
            && $process->timeout === 120
            && $process->environment === ['COMPOSER_HOME' => '/var/composer']);
    }

    public function test_commands_without_placeholders_run_as_given(): void
    {
        Process::fake();

        $this->app->make(CommandRunner::class)->run(['/usr/bin/env', 'true']);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['/usr/bin/env', 'true']);
    }

    public function test_unknown_placeholders_are_rejected(): void
    {
        Process::fake();

        $this->expectExceptionMessage('No binary is configured for "@yarn"');

        $this->app->make(CommandRunner::class)->run(['@yarn', 'install']);
    }

    public function test_failures_report_the_command_and_redacted_output(): void
    {
        Config::set('updater.token', self::TOKEN);
        Process::fake(['*' => Process::result(errorOutput: 'fatal: could not read token '.self::TOKEN, exitCode: 128)]);

        try {
            $this->app->make(CommandRunner::class)->run(['@git', 'fetch']);
            $this->fail('A failing process must throw.');
        } catch (UpdaterException $exception) {
            $this->assertStringContainsString('Command "git fetch" failed (exit code 128)', $exception->getMessage());
            $this->assertStringContainsString('could not read token ********', $exception->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
    }

    public function test_it_returns_the_process_output(): void
    {
        Process::fake(['*' => Process::result("abc123\n")]);

        $this->assertSame("abc123\n", $this->app->make(CommandRunner::class)->run(['@git', 'rev-parse', 'HEAD']));
    }
}
