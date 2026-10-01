<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Update progress shared between the queue worker and the admin panel.
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

    private const string KEY = 'updater:status';

    private const int MAX_LINES = 300;

    /** @param int $staleAfter seconds after which an unfinished run (e.g. a killed worker) no longer blocks new ones */
    public function __construct(private Cache $cache, private int $staleAfter = 3600) {}

    /** @return State */
    public function get(): array
    {
        $stored = $this->cache->get(self::KEY);
        $stored = is_array($stored) ? $stored : [];
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

    public function isBusy(): bool
    {
        $status = $this->get();

        return in_array($status['state'], [self::QUEUED, self::RUNNING], true)
            && $status['updated_at'] !== null
            && Carbon::parse($status['updated_at'])->addSeconds($this->staleAfter)->isFuture();
    }

    public function queue(string $version): void
    {
        $this->write(['state' => self::QUEUED, 'version' => $version, 'step' => null, 'message' => null, 'log' => []]);
    }

    public function start(string $version): void
    {
        $this->write(['state' => self::RUNNING, 'version' => $version, 'step' => null, 'message' => null, 'log' => []]);
    }

    public function step(string $step): void
    {
        $this->write(['step' => $step, 'log' => $this->lines('$ '.$step)]);
    }

    public function append(string $output): void
    {
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
        $this->cache->forever(self::KEY, [...$this->get(), ...$changes, 'updated_at' => Carbon::now()->toIso8601String()]);
    }
}
