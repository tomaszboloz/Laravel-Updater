<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\State;

/**
 * One update at a time, across web requests and background processes. An flock() is released by the operating
 * system when its process ends, so a killed update never leaves a stale lock behind (unlike a cache lock).
 */
final class RunLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path) {}

    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $handle = $this->open();

        if ($handle === null || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== null) {
                fclose($handle);
            }

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** Whether another process holds the lock right now. */
    public function isHeld(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        if (! $this->acquire()) {
            return true;
        }

        $this->release();

        return false;
    }

    /** @return resource|null */
    private function open()
    {
        if (! is_dir(dirname($this->path))) {
            @mkdir(dirname($this->path), 0700, true);
        }

        $handle = @fopen($this->path, 'c');

        return $handle === false ? null : $handle;
    }
}
