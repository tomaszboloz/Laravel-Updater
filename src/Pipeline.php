<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Closure;
use Throwable;
use TomaszBoloz\LaravelUpdater\State\RunLock;

/** Shared frame of every update: one at a time, status log, maintenance mode, recovery on failure. */
final readonly class Pipeline
{
    /** @param array{enabled: bool, retry: int, secret: string|null} $maintenance */
    public function __construct(
        private CommandRunner $runner,
        private Status $status,
        private RunLock $lock,
        private array $maintenance,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work  runs inside maintenance mode
     * @param  (Closure(mixed): void)|null  $recover  runs after a failure with the result of $prepare; its own errors are only logged
     * @param  (Closure(): mixed)|null  $prepare  runs before maintenance mode, e.g. validation or a restore point
     * @return TResult
     */
    public function run(string $target, Closure $work, ?Closure $recover = null, ?Closure $prepare = null): mixed
    {
        if (! $this->lock->acquire()) {
            throw UpdaterException::alreadyRunning();
        }

        [$down, $prepared] = [false, null];

        try {
            $this->status->start($target);

            if ($prepare !== null) {
                $prepared = $prepare();
            }

            $down = $this->down();
            $result = $work();
            $this->status->finish(Status::SUCCEEDED);

            return $result;
        } catch (Throwable $exception) {
            $message = $this->runner->redact($exception->getMessage());
            $this->status->append('! '.$message);

            try {
                if ($recover !== null) {
                    $recover($prepared);
                }
            } catch (Throwable $recovery) {
                $this->status->append('! '.$this->runner->redact($recovery->getMessage()));
            }

            $this->status->finish(Status::FAILED, $message);

            throw $exception;
        } finally {
            if ($down) {
                $this->attempt(['@php', 'artisan', 'up']);
            }

            $this->lock->release();
        }
    }

    public function step(string $label): void
    {
        $this->status->step($label);
    }

    /** @param list<string> $command */
    public function execute(array $command): void
    {
        $this->status->step($this->runner->describe($command));
        $this->runner->run($command, onOutput: $this->status->append(...));
    }

    /**
     * Runs a command whose failure must not stop the recovery.
     *
     * @param  list<string>  $command
     */
    public function attempt(array $command): void
    {
        try {
            $this->execute($command);
        } catch (Throwable $exception) {
            $this->status->append('! '.$this->runner->redact($exception->getMessage()));
        }
    }

    private function down(): bool
    {
        if (! $this->maintenance['enabled']) {
            return false;
        }

        $secret = $this->maintenance['secret'];
        $this->execute(['@php', 'artisan', 'down', '--retry='.$this->maintenance['retry'], ...($secret !== null ? ['--secret='.$secret] : [])]);

        return true;
    }
}
