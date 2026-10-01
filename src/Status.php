<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Support\Carbon;
use TomaszBoloz\LaravelUpdater\State\RunLock;
use TomaszBoloz\LaravelUpdater\State\StateStore;

/**
 * Progress of the current or last run, shared by the background process and the admin panel.
 * Stored in a file, so the "optimize:clear" step of an update cannot wipe it.
 *
 * @phpstan-type State array{state: string, version: string|null, step: string|null, message: string|null, log: list<string>, updated_at: string|null}
 */
final readonly class Status
{
    public const string IDLE = 'idle';

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string SUCCEEDED = 'succeeded';

    public const string FAILED = 'failed';

    private const string FILE = 'status';

    private const int MAX_LINES = 300;

    /** @param int $queuedFor seconds a queued run may wait for its background process before it stops blocking */
    public function __construct(private StateStore $state, private RunLock $lock, private int $queuedFor = 600) {}

    /** @return State */
    public function get(): array
    {
        $stored = $this->state->get(self::FILE);
        $string = static fn (string $key): ?string => is_string($stored[$key] ?? null) ? $stored[$key] : null;

        return [
            'state' => $string('state') ?? self::IDLE,
            'version' => $string('version'),
            'step' => $string('step'),
            'message' => $string('message'),
            'log' => is_array($stored['log'] ?? null) ? array_values(array_filter($stored['log'], 'is_string')) : [],
            'updated_at' => $string('updated_at'),
        ];
    }

    /** A run holds the lock, or was just queued and its background process has not started yet. */
    public function isBusy(): bool
    {
        if ($this->lock->isHeld()) {
            return true;
        }

        $status = $this->get();

        return $status['state'] === self::QUEUED
            && $status['updated_at'] !== null
            && Carbon::parse($status['updated_at'])->addSeconds($this->queuedFor)->isFuture();
    }

    public function queue(string $target): void
    {
        $this->write(['state' => self::QUEUED, 'version' => $target, 'step' => null, 'message' => null, 'log' => []]);
    }

    public function start(string $target): void
    {
        $this->write(['state' => self::RUNNING, 'version' => $target, 'step' => null, 'message' => null, 'log' => []]);
    }

    public function step(string $step): void
    {
        $this->write(['step' => $step, 'log' => $this->lines('$ '.$step)]);
    }

    public function append(string $output): void
    {
        // Colour codes from Composer and Artisan (e.g. "package:discover --ansi") would show up as garbage.
        $output = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a/', '', $output);

        if (trim($output) !== '') {
            $this->write(['log' => $this->lines($output)]);
        }
    }

    public function finish(string $state, ?string $message = null): void
    {
        $this->write(['state' => $state, 'step' => null, 'message' => $message]);
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        $lines = [...$this->get()['log'], ...explode("\n", rtrim(str_replace("\r\n", "\n", $output)))];

        return array_slice($lines, -self::MAX_LINES);
    }

    /** @param array<string, mixed> $changes */
    private function write(array $changes): void
    {
        $this->state->put(self::FILE, [...$this->get(), ...$changes, 'updated_at' => Carbon::now()->toIso8601String()]);
    }
}
