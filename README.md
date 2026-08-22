# Log Lens for Laravel

[![packagist](https://img.shields.io/packagist/v/cliqthemes/log-lens?label=packagist&color=blue&logo=packagist&logoColor=white)](https://packagist.org/packages/cliqthemes/log-lens)
[![downloads](https://img.shields.io/packagist/dt/cliqthemes/log-lens?label=downloads&color=blue&logo=packagist&logoColor=white)](https://packagist.org/packages/cliqthemes/log-lens)
![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-blue)

**[Documentation](https://docs.log-lens.cliqthemes.com/) · [Website](https://log-lens.cliqthemes.com)**

Mount the Log Lens dashboard and JSON API inside an existing Laravel application.
This is a thin adapter over [`cliqthemes/log-lens-core`](https://packagist.org/packages/cliqthemes/log-lens-core); all parsing, storage, and
API logic lives in the core engine.

Requires PHP 8.2+ and Laravel 10, 11, 12, or 13.

[![The Log Lens issue dashboard mounted inside a Laravel app: sources, level and status distribution, indexed-event stats, and fingerprinted issues with status and tags](https://log-lens.cliqthemes.com/screenshots/dashboard.png)](https://log-lens.cliqthemes.com/screenshots/dashboard.png)

## Install

```bash
composer require cliqthemes/log-lens
php artisan vendor:publish --tag=log-lens-assets   # publishes the built UI to public/vendor/log-lens
php artisan vendor:publish --tag=log-lens-config   # optional: config/log-lens.php
```

The service provider is auto-discovered and the UI assets ship pre-built, so no
Node build is needed. Visit `/log-lens` (see [Authorization](#authorization)
first — access is open in `local` by default).

## Authorization

Access is decided by a gate on every route (dashboard and API) — no API key is
used in Laravel mode; the host app's auth is the source of truth. The default,
with nothing configured, is:

- **`local` environment → open** (any browser on the machine, including an
  unauthenticated/incognito tab). Convenient for development; be aware of it.
- **any other environment → denied** to everyone (fails safe when deployed).

Restrict it with any one of these (matching opcodesio/log-viewer):

**1. An auth callback** in a service provider's `boot()`:

```php
use LogLens\Laravel\LogLens;

// Require a logged-in user (blocks incognito even in local):
LogLens::auth(fn ($request) => $request->user() !== null);

// Or restrict further — a permission, a role, or an allowlist:
LogLens::auth(fn ($request) => $request->user()?->can('view-logs') ?? false);
LogLens::auth(fn ($request) => $request->user()?->hasRole('admin') ?? false);
```

**2. A Laravel Gate** named `viewLogLens` (used when no callback is set):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewLogLens', fn ($user) => $user?->can('view-logs') ?? false);
```

**3. Middleware** — add auth middleware to `config('log-lens.middleware')`, e.g.
`['web', 'auth']`, to redirect guests to login before the gate even runs.

## Disabling

Set `LOG_LENS_ENABLED=false` to unregister the routes entirely — a hard
kill-switch independent of the gate above.

## Configuration

`config/log-lens.php`:

- `route_prefix` (default `log-lens`) and `middleware` (default `['web']`).
- `root` — where the SQLite database and `logs/`, `processed/`, `sources/` live
  (default `storage_path('log-lens')`).
- `core` — engine settings passed through to `cliqthemes/log-lens-core` (ingested
  severities, size limits, pagination, connector chunking, SSH defaults,
  processed-archive retention). `core.auth.token` is intentionally empty.

## Documentation

Full documentation: **https://docs.log-lens.cliqthemes.com/** — start with
the [Laravel package guide](https://docs.log-lens.cliqthemes.com/Laravel-Package).

## Artisan

```bash
php artisan log-lens:import path/to/logs --app=default
php artisan log-lens:sync --app=default            # all connectors
php artisan log-lens:sync --app=default --connector=2
```

Schedule `log-lens:sync` in the app's console kernel for continuous connector
ingestion.

## How it works

The provider registers one route at the prefix that mirrors the standalone front
controller: requests without an `api` parameter return the SPA shell; requests
with one are translated into a `LogLens\Http\LogLensRequest` and passed to the
core `LogLens\Kernel`, whose `LogLensResponse` becomes a Laravel JSON
response. The SPA calls the API with same-prefix relative URLs, and its assets are
served from `public/vendor/log-lens`.

MIT licensed. Release history: [CHANGELOG.md](CHANGELOG.md) and
[releases](https://github.com/cliqthemes/log-lens/releases). Bugs, requests, and
security advisories for both packages go to
[cliqthemes/log-lens](https://github.com/cliqthemes/log-lens/issues).
