<?php

declare(strict_types=1);

namespace LogLens\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogLens\Laravel\Reporter\LogLensReporter;
use Throwable;

/**
 * Authorization gate for the Log Lens dashboard and API.
 *
 * Access is decided in this order (mirroring opcodesio/log-viewer):
 *   1. a callback registered via LogLens::auth();
 *   2. otherwise a `viewLogLens` Laravel Gate, if one is defined;
 *   3. otherwise access is granted only in the `local` environment.
 *
 * Register a callback in a service provider's boot():
 *
 *   LogLens::auth(fn (Request $request) => $request->user()?->can('view-logs'));
 *
 * or define the gate in AuthServiceProvider:
 *
 *   Gate::define('viewLogLens', fn (?User $user) => $user?->hasRole('admin') === true);
 *
 * Gate on something narrower than "is logged in". Whoever passes this check
 * reaches the dashboard with `log-lens.default-role` (owner out of the box):
 * settings, plugins, connector credentials, and the raw bytes of every log
 * line. If the audience has to be broad, lower `log-lens.default-role` and
 * grant owner deliberately through the `log-lens.identity` callable.
 */
final class LogLens
{
    /** @var (callable(Request): bool)|null */
    private static $authCallback = null;

    /** @param callable(Request): bool $callback */
    public static function auth(callable $callback): void
    {
        self::$authCallback = $callback;
    }

    public static function authorized(Request $request): bool
    {
        if (self::$authCallback !== null) {
            return (bool) (self::$authCallback)($request);
        }
        if (Gate::has('viewLogLens')) {
            return Gate::allows('viewLogLens');
        }
        return app()->environment('local');
    }

    /**
     * Report an exception to Log Lens by hand, alongside the automatic capture:
     *
     *   LogLens::capture($exception);
     */
    public static function capture(Throwable $exception): void
    {
        self::reporter()?->capture($exception);
    }

    /** Report an ad-hoc message (e.g. LogLens::message('Drift detected', 'WARNING')). */
    public static function message(string $message, string $severity = 'INFO'): void
    {
        self::reporter()?->captureMessage($message, $severity);
    }

    private static function reporter(): ?LogLensReporter
    {
        if (!function_exists('app') || !app()->bound(LogLensReporter::class)) {
            return null;
        }
        return app(LogLensReporter::class);
    }
}
