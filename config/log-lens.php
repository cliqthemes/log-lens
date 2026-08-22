<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Set LOG_LENS_ENABLED=false to unregister the routes entirely (a hard
    | kill-switch independent of the authorization gate).
    */
    'enabled' => env('LOG_LENS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Route mounting
    |--------------------------------------------------------------------------
    | The dashboard and JSON API are served under this URI prefix, wrapped in
    | the given middleware group. Authorization is handled by the gate below,
    | not by an API key (that is the standalone deployment's mechanism).
    */
    'route_prefix' => env('LOG_LENS_ROUTE_PREFIX', 'log-lens'),
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Self-authenticating receivers
    |--------------------------------------------------------------------------
    | Two endpoints prove who they are on their own and are therefore mounted
    | outside the middleware above and outside the access gate:
    |
    |   POST {prefix}/ingest          — carries a per-application ingest key
    |   POST {prefix}/linear-webhook  — carries an HMAC over the raw body
    |
    | Their callers are external and sessionless (a browser or worker SDK,
    | Linear's webhook delivery), so the 'web' group's CSRF check and the gate
    | would reject every legitimate request. Add middleware here if you want
    | your own throttling or IP allowlist in front of them; an empty list is
    | not "unprotected" — each endpoint still verifies its own credential and
    | rejects anything else with a 401.
    */
    'receiver-middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Extra request headers
    |--------------------------------------------------------------------------
    | Headers the dashboard SPA sends with every API request — e.g. a bearer
    | token for a custom auth guard. An array of header => value, or a callable
    | (given the request) returning that array. Session + CSRF are handled
    | automatically, so most apps can leave this empty.
    */
    'request_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Actor identity
    |--------------------------------------------------------------------------
    | Who a dashboard action is attributed to (and authorized as). Leave null to
    | derive it from the authenticated host user (auth()->user()) as an "owner".
    | Provide a callable, given the request and the current application id (the
    | raw `?app=` value — null if omitted), returning
    | ['id' => ..., 'label' => ..., 'roles' => ['owner'|'editor'|'viewer', ...]]
    | to map host users/roles onto Log Lens roles. Roles can vary per
    | application (e.g. a host on spatie/laravel-permission checking a
    | per-team role) — Log Lens has no opinion on how you determine them, it
    | only reads the result. The actor is set server-side, never from client
    | headers, so it cannot be spoofed.
    |
    |   'identity' => function ($request, $applicationId) {
    |       $user = $request->user();
    |       if (!$user) return null;
    |       $role = $user->hasRole("log-lens-owner-{$applicationId}") ? 'owner' : 'viewer';
    |       return ['id' => $user->id, 'label' => $user->name, 'roles' => [$role]];
    |   },
    */
    'identity' => null,

    /*
    |--------------------------------------------------------------------------
    | Default role
    |--------------------------------------------------------------------------
    | The role given to an authenticated host user when 'identity' above is not
    | configured (or returns no roles). 'owner' means "the access gate is the
    | access control": everyone you let through can administer Log Lens —
    | change settings, manage plugins, provision applications, delete data.
    |
    | If your gate admits a broad audience (say, every authenticated user),
    | narrow this to 'editor' (everything except administration) or 'viewer'
    | (read-only) and hand out 'owner' deliberately through 'identity'.
    |
    | Recognised: 'owner' | 'editor' | 'viewer'. An unrecognised value grants
    | nothing, so a typo fails closed.
    */
    'default-role' => env('LOG_LENS_DEFAULT_ROLE', 'owner'),

    /*
    |--------------------------------------------------------------------------
    | Assignable users
    |--------------------------------------------------------------------------
    | Who an issue in the current application can be assigned to. Log Lens has
    | no user table of its own, so without this callable there is simply nobody
    | to assign to (the dashboard's assignee picker stays empty) — this is a
    | Laravel-hosted feature; standalone has no equivalent. Provide a callable,
    | given the request and the current application id, returning a list of
    | ['id' => ..., 'label' => ...]:
    |
    |   'assignable-users' => function ($request, $applicationId) {
    |       return User::query()
    |           ->whereHas('teams', fn ($q) => $q->where('app_id', $applicationId))
    |           ->get(['id', 'name'])
    |           ->map(fn ($u) => ['id' => (string) $u->id, 'label' => $u->name])
    |           ->all();
    |   },
    */
    'assignable-users' => null,

    /*
    |--------------------------------------------------------------------------
    | Workspace root
    |--------------------------------------------------------------------------
    | Where Log Lens keeps its SQLite database and the logs/processed/sources
    | directories for this app. Defaults to a private folder under storage/.
    */
    'root' => storage_path('log-lens'),

    /*
    |--------------------------------------------------------------------------
    | First-hand error reporting
    |--------------------------------------------------------------------------
    | When enabled, unhandled exceptions are captured automatically (with request
    | and user context) and reported to Log Lens — no code changes required.
    | Also use LogLens::capture($e) / LogLens::message() for manual reports.
    |
    | driver: 'local' writes straight into the embedded Log Lens database
    | in-process (no network/key); 'http' POSTs to a remote Log Lens ingest
    | endpoint using an ingest key from Settings → Plugins → Ingest.
    */
    'reporter' => [
        'enabled' => env('LOG_LENS_REPORTER', false),
        'driver' => env('LOG_LENS_REPORTER_DRIVER', 'local'),
        'application' => env('LOG_LENS_APP', 'default'),
        'ingest_url' => env('LOG_LENS_INGEST_URL', ''),
        'key' => env('LOG_LENS_INGEST_KEY', ''),
        'environment' => env('APP_ENV', 'production'),
        'release' => env('LOG_LENS_RELEASE', ''),
        'timeout' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Core engine settings
    |--------------------------------------------------------------------------
    | Passed verbatim to the cliqthemes/log-lens-core Config layer. `auth.token` is left
    | empty because access is gated by LogLens::auth() (the host app's auth),
    | not by the core API key.
    */
    'core' => [
        'auth' => [
            'token' => '',
        ],
        'ingestion' => [
            'default_severities' => ['ERROR', 'WARNING'],
            'log_file_pattern' => '/\.log(?:\.\d+)?$/i',
            'sample_bytes' => 65536,
            'capture_limit' => 4194304,
            'message_limit' => 120000,
            'context_preview_limit' => 12000,
        ],
        'pagination' => [
            'default_limit' => 50,
            'max_limit' => 200,
        ],
        'sync' => [
            // Set this when the PHP CLI is not available at PHP_BINDIR/php.
            'php_binary' => env('LOG_LENS_PHP_BINARY', ''),
            // Optional POSIX shell path for unusual Unix/container layouts.
            'shell_binary' => env('LOG_LENS_SHELL_BINARY', ''),
            'chunk_size' => 8388608,
            'max_discovered_files' => 10000,
            'prefix_hash_bytes' => 1048576,
        ],
        'ssh' => [
            'default_port' => 22,
            'connect_timeout' => 10,
        ],
        'linear' => [
            // Optional Linear integration. Leave the key empty to manage it from
            // the dashboard (Settings → Linear); set it here to keep it out of
            // the database and lock it in the UI.
            'api_key' => env('LOG_LENS_LINEAR_API_KEY', ''),
            // Encrypts UI-entered secrets at rest. Falls back to Laravel's APP_KEY
            // so a standard Laravel app gets at-rest encryption automatically.
            'secret' => env('LOG_LENS_SECRET', env('APP_KEY', '')),
            'webhook_secret' => env('LOG_LENS_LINEAR_WEBHOOK_SECRET', ''),
            'endpoint' => 'https://api.linear.app/graphql',
            'timeout' => 15,
        ],
        'database' => [
            // Storage engine (C-1). 'sqlite' (default) needs no configuration;
            // Postgres/MySQL are opt-in, never enforced — see
            // docs/reference/database-drivers.md. Set LOG_LENS_DB_DRIVER (and
            // the connection settings below) the same way you would for the
            // standalone app.
            'driver' => env('LOG_LENS_DB_DRIVER', 'sqlite'),
            'busy_timeout' => 5000,
            'pgsql' => [
                'host' => env('LOG_LENS_DB_HOST', '127.0.0.1'),
                'port' => env('LOG_LENS_DB_PORT', 5432),
                'database' => env('LOG_LENS_DB_NAME', 'log_lens'),
                'username' => env('LOG_LENS_DB_USER', 'postgres'),
                'password' => env('LOG_LENS_DB_PASSWORD', ''),
                'schema_prefix' => 'app_',
            ],
            'mysql' => [
                'host' => env('LOG_LENS_DB_HOST', '127.0.0.1'),
                'port' => env('LOG_LENS_DB_PORT', 3306),
                'username' => env('LOG_LENS_DB_USER', 'root'),
                'password' => env('LOG_LENS_DB_PASSWORD', ''),
                'database_prefix' => 'log_lens_',
            ],
        ],
        'retention' => [
            'processed_max_age_days' => 0,
            'processed_max_files' => 0,
        ],
    ],
];
