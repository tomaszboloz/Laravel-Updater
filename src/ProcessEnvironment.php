<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Background processes started from a web server (PHP-FPM, Herd, Valet...) often get a minimal environment:
 * no PATH entries for Composer, npm or node, and no HOME. This adds the usual locations, so "composer" and
 * "npm" (and the "node" that npm runs) are found the same way as in a terminal.
 */
final class ProcessEnvironment
{
    /** Turned off in tests that assert exact commands and environments. */
    public static bool $enabled = true;

    /** @var list<string>|null */
    private static ?array $directories = null;

    /** @return array<string, string> PATH (and HOME when missing) for every step */
    public static function variables(): array
    {
        if (! self::$enabled) {
            return [];
        }

        $variables = ['PATH' => implode(PATH_SEPARATOR, self::directories())];
        $home = getenv('HOME');

        if (($home === false || $home === '') && ($detected = self::home()) !== null) {
            $variables['HOME'] = $detected;
        }

        return $variables;
    }

    /** Absolute path of a binary given by name, e.g. "composer" => "/opt/homebrew/bin/composer". */
    public static function find(string $binary): string
    {
        if (! self::$enabled || str_contains($binary, DIRECTORY_SEPARATOR)) {
            return $binary;
        }

        return (new ExecutableFinder)->find($binary, null, self::directories()) ?? $binary;
    }

    /** @return list<string> */
    private static function directories(): array
    {
        if (self::$directories !== null) {
            return self::$directories;
        }

        $home = getenv('HOME') ?: self::home();
        $candidates = [
            ...explode(PATH_SEPARATOR, (string) getenv('PATH')),
            dirname(PHP_BINARY),
            '/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin', '/opt/local/bin', '/snap/bin',
        ];

        if (is_string($home) && $home !== '') {
            array_push(
                $candidates,
                $home.'/.composer/vendor/bin',
                $home.'/.config/composer/vendor/bin',
                $home.'/Library/Application Support/Herd/bin',
                $home.'/.config/herd-lite/bin',
                $home.'/.volta/bin',
                ...(glob($home.'/.nvm/versions/node/*/bin') ?: []),
            );
        }

        return self::$directories = array_values(array_unique(array_filter($candidates, static fn (string $path): bool => $path !== '' && is_dir($path))));
    }

    private static function home(): ?string
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_geteuid')) {
            return null;
        }

        $user = posix_getpwuid(posix_geteuid());

        return is_array($user) && $user['dir'] !== '' ? $user['dir'] : null;
    }
}
