<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater;

/**
 * GitHub credentials for git and Composer, passed through the environment so tokens never appear in process arguments.
 * Every repository gets its own token: git receives a per-repository "extraheader", Composer a "github-oauth" token
 * and falls back to git for repositories that token cannot read.
 */
final readonly class Credentials
{
    public function __construct(private Settings $settings) {}

    /** @return array<string, string> */
    public function environment(): array
    {
        $tokens = $this->tokens();

        if ($tokens === []) {
            return [];
        }

        $environment = ['GIT_TERMINAL_PROMPT' => '0', 'COMPOSER_AUTH' => (string) json_encode(
            ['github-oauth' => ['github.com' => $this->settings->token() ?? reset($tokens)]],
            JSON_UNESCAPED_SLASHES,
        )];
        $index = 0;

        // Distinct paths never overlap, so git sends exactly one Authorization header per request.
        foreach ($tokens as $repository => $token) {
            foreach (["https://github.com/{$repository}", "https://github.com/{$repository}.git"] as $url) {
                $environment["GIT_CONFIG_KEY_{$index}"] = "http.{$url}.extraheader";
                $environment["GIT_CONFIG_VALUE_{$index}"] = 'AUTHORIZATION: basic '.self::basic($token);
                $index++;
            }
        }

        return [...$environment, 'GIT_CONFIG_COUNT' => (string) $index];
    }

    /** @return list<string> values that must never appear in logs */
    public function secrets(): array
    {
        $secrets = [];

        foreach ($this->tokens() as $token) {
            $secrets[] = $token;
            $secrets[] = self::basic($token);
        }

        return array_values(array_unique($secrets));
    }

    /** @return array<string, string> repository => token */
    private function tokens(): array
    {
        $tokens = [];
        $repository = $this->settings->repository();
        $token = $this->settings->token();

        if ($repository !== null && $token !== null) {
            $tokens[$repository] = $token;
        }

        foreach ($this->settings->packages() as $package) {
            if ($package['token'] !== null) {
                $tokens[$package['repository']] ??= $package['token'];
            }
        }

        return $tokens;
    }

    private static function basic(string $token): string
    {
        return base64_encode('x-access-token:'.$token);
    }
}
