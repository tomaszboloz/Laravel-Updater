<?php

declare(strict_types=1);

namespace TomaszBoloz\LaravelUpdater\Packages;

use TomaszBoloz\LaravelUpdater\Release;

/** A Composer package the application requires directly, with the latest version found by the last check. */
final readonly class Package
{
    /** Composer package name; also guarantees the name cannot be read as a command-line option. */
    public const string NAME_PATTERN = '/\A[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*\z/';

    public const string SAFE = 'semver-safe-update';

    public const string MAJOR = 'update-possible';

    public function __construct(
        public string $name,
        public string $version,
        public bool $dev = false,
        public bool $private = false,
        public ?string $latest = null,
        public ?string $status = null,
    ) {}

    /** Compared with the installed version, so the flag clears as soon as the package is updated. */
    public function hasUpdate(): bool
    {
        return $this->latest !== null
            && version_compare(Release::normalize($this->latest), Release::normalize($this->version), '>');
    }

    /**
     * Installable from the panel: within the current constraint (a major update needs a new constraint in
     * composer.json) and not a dev dependency (production installs run without dev packages).
     */
    public function canUpdate(): bool
    {
        return $this->hasUpdate() && $this->status !== self::MAJOR && ! $this->dev;
    }
}
