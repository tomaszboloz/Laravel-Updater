# Laravel Updater

[![CI](https://github.com/tomaszboloz/Laravel-Updater/actions/workflows/ci.yml/badge.svg)](https://github.com/tomaszboloz/Laravel-Updater/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/tomaszboloz/Laravel-Updater)](https://github.com/tomaszboloz/Laravel-Updater/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Laravel Updater keeps a Laravel application up to date from the **Filament admin panel** or Artisan:

- the **whole application**, from its own GitHub releases (public or private repository);
- **every installed Composer package**, one at a time or all at once;
- **private packages** (e.g. your own plugins) from private GitHub repositories, each with its own token.

It **monitors** everything automatically on a schedule, shows the number of available updates in the
navigation badge, and has a manual **Check for updates** button.

When a newer application release appears, one click (or one command) runs the whole deployment:

1. takes a lock, so only one update runs at a time;
2. puts the site into maintenance mode (with an optional bypass secret);
3. installs the new code: `git fetch` + `checkout` of the release tag, or the release zipball on hosts without git;
4. runs your steps: `composer install`, `php artisan migrate --force`, `npm ci`, `npm run build`,
   `php artisan optimize:clear`, `php artisan optimize`, `php artisan queue:restart` (all configurable);
5. remembers the installed version and brings the site back up.

If any step fails, the code is rolled back to the previous commit, the recovery steps run
(`composer install`, `optimize:clear`), the site goes back up and the error is shown in the panel.

## Packages

The **Installed packages** table lists every direct dependency from `composer.json` (with `dev` and
`private` badges), the installed version and the latest one found by the last check:

- **Update** on a row runs `composer update vendor/package --with-dependencies`, then migrations and cache clearing;
- **Update all packages** runs the same for every package;
- a new **major** version is shown but not installable from the panel, because it needs a new constraint
  in `composer.json` (a code change that belongs in a release of the application).

If a step fails, `composer.lock` is restored and `composer install` brings `vendor/` back to the previous state.
Only installed direct dependencies can be updated: package names from the browser are checked against them.

### Private packages

**Updates → Settings → Private packages** holds any number of private Composer packages hosted on GitHub:
Composer name, `owner/repository` and a token (fine-grained, *Contents: Read-only*). Tokens are encrypted and never
sent back to the browser; an empty field keeps the saved token.

Private packages are monitored by their **tags**. During updates every repository gets its own token:
git receives a per-repository `http.<url>.extraheader`, Composer receives `COMPOSER_AUTH`, all through the
environment, never through process arguments. The packages still need their `repositories` entry
(`"type": "vcs"`) in `composer.json`, as usual for private packages.

### Monitoring

`updater:check` checks the application release and all packages in one go (`composer outdated --direct`
plus GitHub tags of private packages). It runs:

- on the **schedule** from `updater.schedule` (default every 6 hours; requires `* * * * * php artisan schedule:run`);
- from the **Check for updates** button (queued, the page refreshes by itself);
- after every update, so the list is always current.

When something newer is found, `UpdatesAvailable` is dispatched (listen to it for e-mail or Slack notifications),
and the navigation badge shows the count.

## Use cases

- Client sites and SaaS installations that should update from the admin panel, without SSH access.
- Many copies of one application (franchises, white-label installs) that track a single GitHub repository.
- Shared hosting without git: the `archive` strategy downloads the release zipball over HTTPS.
- Private repositories: the token is entered in the panel, stored encrypted, sent only to GitHub and never logged.
- Scheduled, unattended updates: `php artisan updater:run --force` from the scheduler.
- Agencies with many private plugins shared between projects: every project shows which plugin versions it runs
  and updates them with one click.

## Requirements

- PHP 8.3+, Laravel 12 or 13
- A queue worker, because updates run in a queued job (web requests would time out)
- A shared cache store with atomic locks (`file`, `redis`, `database`, `memcached`...)
- `git` (for the default strategy) or the `zip` extension (for the `archive` strategy)
- Filament 4 or 5, only if you want the admin page
- Releases in your app's repository tagged with semantic versions: `v1.4.0` or `1.4.0`

## Installation

```bash
composer require tomaszboloz/laravel-updater
php artisan vendor:publish --tag=updater-config   # optional
```

### Settings: in the admin panel

Open **Updates → Settings** in the Filament panel and fill in:

- **Repository**: `owner/repository` that publishes the releases;
- **Access token**: only for private repositories (see below). It is stored **encrypted** with `APP_KEY`
  and never sent back to the browser. Leave the field empty to keep the saved token;
- **Update strategy**: `git` or `archive`;
- **Installed version**: changes automatically after every update. Correct it only if it does not match the deployed code;
- **Maintenance mode**: on/off, `Retry-After` and an optional bypass secret.

Panel settings are saved in `storage/app/updater/settings.json` (permissions `0600`, secrets encrypted)
and **take precedence over config and `.env`**. Without Filament, or as defaults before the first save,
the same values can come from `.env`:

```dotenv
UPDATER_REPOSITORY=your-org/your-app
UPDATER_GITHUB_TOKEN=
UPDATER_CURRENT_VERSION=1.0.0
```

Update **steps** (the commands that run) are configured only in `config/updater.php`, on purpose:
letting panel users define commands would let them run arbitrary code on the server.

> Settings are encrypted with `APP_KEY`. If you rotate the key, enter the token again.

### Private repositories

Create a **fine-grained personal access token** limited to the one repository, with
**Repository permissions → Contents: Read-only**, and save it in **Updates → Settings**.
Public repositories need no token, but a token raises GitHub's API rate limit.

### Who may update: the gate

The admin page and its actions require the `updater.manage` ability, so nobody can update until
you allow it (secure by default). Define the gate, for example in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('updater.manage', fn ($user) => $user->hasRole('super_admin'));
```

With Filament Shield, the `super_admin` role already passes through its `Gate::before` callback.
Change the ability name with the `updater.ability` config option.

### Filament admin panel

Register the plugin in your panel provider:

```php
use TomaszBoloz\LaravelUpdater\Filament\UpdaterPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            UpdaterPlugin::make()
                ->navigationGroup('Settings')
                ->navigationSort(90),
        );
}
```

The **Updates** page shows the installed and latest version, the release notes (rendered as escaped
Markdown), a **Settings** button, a **Check for updates** button and an **Update now** button with confirmation. While an update
runs, the page refreshes every five seconds and shows the live log.

The plugin follows the Filament 5 plugin conventions: `make()` resolves it from the container, `get()`
returns the instance registered on the current panel, and the service provider extends Spatie's
`PackageServiceProvider`.

### Queue worker

The **Update now** button dispatches the `RunUpdate` job. Keep a worker running, for example under
Supervisor, with a timeout longer than your slowest update:

```bash
php artisan queue:work --timeout=3600
```

Choose a dedicated connection or queue with `UPDATER_QUEUE_CONNECTION` and `UPDATER_QUEUE`.

## Artisan

```bash
php artisan updater:check          # application release and every package
php artisan updater:packages       # update all packages (asks for confirmation in production)
php artisan updater:packages acme/plugin --force   # update one package
php artisan updater:packages --queue               # hand it to the queue worker
php artisan updater:run            # update now (asks for confirmation in production)
php artisan updater:run --force    # no confirmation, e.g. from the scheduler or CI
php artisan updater:run --queue    # hand the update to the queue worker
```

To update automatically every night:

```php
// routes/console.php
Schedule::command('updater:run --force')->dailyAt('03:00')->withoutOverlapping();
```

## Configuration

Options marked **panel** are edited in **Updates → Settings**; config and `.env` are their fallback.

| Option | Default | Description |
| --- | --- | --- |
| `repository` (panel) | `UPDATER_REPOSITORY` | `owner/repository` that publishes the releases |
| `token` (panel) | `UPDATER_GITHUB_TOKEN` | Token for private repositories, stored encrypted |
| `strategy` (panel) | `git` | `git` (checkout of the tag, with rollback) or `archive` (zipball, no rollback) |
| `current_version` (panel) | `0.0.0` | Version before the first update; later stored in `storage/app/updater/version` |
| `ability` | `updater.manage` | Gate ability for the admin page |
| `check_cache_minutes` | `10` | How long the latest release and tags are cached |
| `schedule` | `0 */6 * * *` | Cron expression of the automatic check, `null` turns it off |
| `package_steps` | `composer update {packages}`, migrate, caches | Commands of a package update; `{packages}` is the package or nothing for all |
| `package_recovery_steps` | `composer install`, `optimize:clear` | Commands run after `composer.lock` has been restored |
| `git_ignored_changes` | `composer.lock` | Tracked files whose local changes do not block an application update |
| `timeout` | `900` | Seconds allowed for each step |
| `maintenance` (panel) | enabled, retry 60 | Maintenance mode during the update, with an optional bypass secret |
| `binaries` | `php`, `composer`, `npm`, `git` | Binaries behind `@php`, `@composer`, `@npm`, `@git`; e.g. `"php8.3 /usr/local/bin/composer"` |
| `environment` | `HOME`, `COMPOSER_HOME` | Extra environment variables; workers often lack `HOME` |
| `steps` | see below | Commands run after the new code is in place |
| `recovery_steps` | `composer install`, `optimize:clear` | Commands run after a rollback |
| `preserve` | `.env`, `storage`, `vendor`... | Paths the `archive` strategy never overwrites |
| `queue` | default connection | `connection`, `name` and `timeout` of the update job |
| `cache_store` | default store | Store shared by web and workers, used for the lock and the status |

Steps are arrays of arguments and are never passed through a shell:

```php
'steps' => [
    ['@composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
    ['@php', 'artisan', 'migrate', '--force'],
    ['@npm', 'ci', '--no-audit', '--no-fund'],
    ['@npm', 'run', 'build'],
    ['@php', 'artisan', 'optimize:clear'],
    ['@php', 'artisan', 'optimize'],
    ['@php', 'artisan', 'queue:restart'],
    // ['@php', 'artisan', 'filament:optimize'],
    // ['@php', 'artisan', 'backup:run', '--only-db'],
],
```

Remove the npm steps if your app has no front-end build, and add anything your deployment needs.

`--no-dev` is dropped from Composer commands when the application was installed **with** dev packages
(`vendor/composer/installed.json` says so), so running an update on a development machine does not remove
tools such as PHPUnit, Pint or debug bars. Production installs (`composer install --no-dev`) stay without them.

## Update strategies

**`git` (default).** The application directory is a clone of the repository with an `origin` remote. The
updater refuses to run when tracked files have local changes, fetches only the release tag, checks it out
and keeps the previous commit as a restore point. If a step fails, it checks the previous commit out again.

**`archive`.** For hosts without git. The release zipball is downloaded from the GitHub API, checked for
unsafe paths and copied over the application, skipping the `preserve` paths. Files deleted in the
release stay in place, and there is no automatic rollback, so keep backups.

## Events

| Event | When |
| --- | --- |
| `TomaszBoloz\LaravelUpdater\Events\UpdateSucceeded` | The release is installed (`$event->release`) |
| `TomaszBoloz\LaravelUpdater\Events\UpdateFailed` | The update failed (`$event->release`, `$event->exception`) |
| `TomaszBoloz\LaravelUpdater\Events\UpdatesAvailable` | A check found updates (`$event->release`, `$event->packages`) |
| `TomaszBoloz\LaravelUpdater\Events\PackagesUpdated` | Packages updated (`$event->package`, `null` = all) |
| `TomaszBoloz\LaravelUpdater\Events\PackagesUpdateFailed` | A package update failed (`$event->package`, `$event->exception`) |

Listen to them to send notifications, for example to Slack or e-mail.

## Security

- **Authorization:** the page and every Livewire action re-check the gate; with no gate defined, access is denied.
- **No shell:** steps run as argument arrays through Laravel's Process component, so nothing is shell-interpolated.
- **Validated input:** release tags must be semantic versions and repository names must match GitHub's
  format, which blocks option and path injection into `git` and the API. Restore points must be commit hashes.
- **Secrets:** the GitHub token reaches git only through `GIT_CONFIG_*` environment variables, never through
  process arguments. The token and the maintenance secret are masked in logs, the status and exceptions.
- **Settings at rest:** the token and the bypass secret are encrypted with `APP_KEY` in a `0600` file and never
  rendered back into the form; update commands cannot be changed from the panel.
- **HTTPS only:** the API URL must use `https://`.
- **Archives:** entries with `..`, absolute paths, backslashes or drive letters abort the update before
  anything is copied (zip slip). Symlinks are not copied, and `.env`, `storage` and `vendor` are never overwritten.
- **Release notes:** Markdown is rendered with raw HTML escaped and unsafe links removed.
- **One update at a time:** an atomic cache lock plus a unique queued job, with `tries = 1`.
- **Local changes:** the `git` strategy refuses to overwrite changed tracked files.

Migrations are not rolled back automatically. Write backward-compatible migrations and back up the database,
for example with a `backup:run` step before `migrate`.

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Testing

```bash
composer test      # PHPUnit: unit, feature, Filament and real git integration tests
composer analyse   # PHPStan with Larastan, level max
composer format    # Laravel Pint
```

## Releases

Every push to `main` that passes CI is tagged automatically from
[Conventional Commits](https://www.conventionalcommits.org/): `fix:` → patch, `feat:` → minor,
`feat!:` or `BREAKING CHANGE:` → major. A GitHub release with notes is created for each tag.

## Author

**Tomasz Bołoz** · [www.damtox.pl](https://www.damtox.pl)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
