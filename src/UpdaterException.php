<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use RuntimeException;

final class UpdaterException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The updater is not configured: set UPDATER_REPOSITORY to "owner/repository" and use an https:// API URL.');
    }

    public static function invalidTag(string $tag): self
    {
        return new self(sprintf('Release tag "%s" is not a semantic version.', $tag));
    }

    public static function github(int $status): self
    {
        return new self(in_array($status, [401, 403], true)
            ? sprintf('GitHub rejected the request (HTTP %d). Check UPDATER_GITHUB_TOKEN and its "Contents: read" permission.', $status)
            : sprintf('GitHub API request failed (HTTP %d).', $status));
    }

    public static function alreadyRunning(): self
    {
        return new self('Another update is already running.');
    }

    public static function dirtyWorkingTree(): self
    {
        return new self('The working tree has local changes to tracked files. Commit or discard them before updating.');
    }

    public static function unsafeArchive(string $entry): self
    {
        return new self(sprintf('The release archive contains an unsafe path "%s".', $entry));
    }

    public static function unreadableArchive(): self
    {
        return new self('The release archive could not be opened or has an unexpected layout.');
    }

    public static function missingExtension(string $extension): self
    {
        return new self(sprintf('The "%s" PHP extension is required by this update strategy.', $extension));
    }

    public static function unknownBinary(string $name): self
    {
        return new self(sprintf('No binary is configured for "@%s" (updater.binaries).', $name));
    }

    public static function unknownStrategy(string $strategy): self
    {
        return new self(sprintf('Unknown update strategy "%s"; use "git" or "archive".', $strategy));
    }

    public static function stepFailed(string $command, ?int $exitCode, string $output): self
    {
        return new self(sprintf("Command \"%s\" failed (exit code %s).\n%s", $command, $exitCode ?? 'n/a', mb_substr(trim($output), -2000)));
    }
}
