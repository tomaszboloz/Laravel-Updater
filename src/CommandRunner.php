<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Closure;
use Illuminate\Process\Factory as Process;
use SensitiveParameter;
use Symfony\Component\Process\PhpExecutableFinder;

/** Runs update steps as separate processes (never through a shell) and keeps secrets out of their output. */
final readonly class CommandRunner
{
    private const array CACHING_COMMANDS = ['optimize', 'config:cache', 'route:cache', 'view:cache', 'event:cache', 'filament:optimize'];

    /**
     * @param  array<string, string|null>  $binaries  "@name" placeholder => binary, e.g. "composer" or "php8.3 /usr/bin/composer"
     * @param  array<string, string|null>  $environment
     * @param  list<string>  $secrets
     * @param  array<string, string>  $credentials  environment added only for "@git" and "@composer" commands
     * @param  bool  $developmentInstall  the app was installed with require-dev packages (a development machine):
     *                                    "--no-dev" is dropped so tools like Pint or PHPUnit stay, and commands that
     *                                    cache config/routes/views are skipped so .env changes keep working
     */
    public function __construct(
        private Process $process,
        private string $basePath,
        private array $binaries = [],
        private array $environment = [],
        private int $timeout = 900,
        #[SensitiveParameter] private array $secrets = [],
        #[SensitiveParameter] private array $credentials = [],
        private bool $developmentInstall = false,
    ) {}

    /**
     * @param  list<string>  $command  the first item may be a placeholder: "@php", "@composer", "@npm", "@git"
     * @param  array<string, string>  $environment
     * @param  (Closure(string): void)|null  $onOutput  receives redacted output chunks
     */
    public function run(array $command, array $environment = [], ?Closure $onOutput = null): string
    {
        if ($this->developmentInstall && ($command[0] ?? '') === '@php' && ($command[1] ?? '') === 'artisan'
            && in_array($command[2] ?? '', self::CACHING_COMMANDS, true)) {
            $onOutput?->__invoke('skipped on a development install');

            return '';
        }

        $listener = $onOutput === null ? null : fn (string $type, string $buffer) => $onOutput($this->redact($buffer));

        $result = $this->process->newPendingProcess()
            ->path($this->basePath)
            ->timeout($this->timeout)
            ->env([
                ...ProcessEnvironment::variables(),
                ...array_filter($this->environment, static fn (?string $value): bool => $value !== null && $value !== ''),
                ...(in_array($command[0] ?? '', ['@git', '@composer'], true) ? $this->credentials : []),
                ...$environment,
            ])
            ->run($this->resolve($command), $listener);

        if ($result->failed()) {
            throw UpdaterException::stepFailed(
                $this->describe($command),
                $result->exitCode(),
                $this->redact(trim($result->errorOutput()."\n".$result->output())),
            );
        }

        return $result->output();
    }

    /** @param list<string> $command */
    public function describe(array $command): string
    {
        $command = $this->effective($command);
        $command[0] = ltrim($command[0] ?? '', '@');

        return $this->redact(implode(' ', $command));
    }

    public function redact(string $text): string
    {
        $secrets = array_filter($this->secrets, static fn (string $secret): bool => $secret !== '');

        return $secrets === [] ? $text : str_replace($secrets, '********', $text);
    }

    /**
     * The command as it will run: without "--no-dev" for Composer on a development install.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function effective(array $command): array
    {
        if (($command[0] ?? '') !== '@composer' || ! $this->developmentInstall) {
            return $command;
        }

        return array_values(array_filter($command, static fn (string $argument): bool => $argument !== '--no-dev'));
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function resolve(array $command): array
    {
        $first = $command[0] ?? throw UpdaterException::unknownBinary('');

        if (! str_starts_with($first, '@')) {
            return $command;
        }

        $name = substr($first, 1);

        $command = $this->effective($command);
        $binary = $this->binaries[$name] ?? null;

        if ($name === 'php' && ($binary === null || $binary === '')) {
            $binary = (new PhpExecutableFinder)->find(false);
        }

        if (! is_string($binary) || trim($binary) === '') {
            throw UpdaterException::unknownBinary($name);
        }

        $parts = preg_split('/\s+/', trim($binary)) ?: [];
        $parts[0] = ProcessEnvironment::find($parts[0]);

        return [...$parts, ...array_slice($command, 1)];
    }
}
