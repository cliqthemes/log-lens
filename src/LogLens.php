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
 * Access is decided in this order:
 *   0. a valid `log-lens.agent_token` presented on an API request (never the
 *      dashboard shell) — see the config file. Checked first and
 *      independently of the rules below, since it exists specifically for a
 *      sessionless caller (a CI job, the bundled agent skill) that the rules
 *      below have no way to admit;
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
        if (self::agentTokenMatches($request)) {
            return true;
        }
        if (self::$authCallback !== null) {
            return (bool) (self::$authCallback)($request);
        }
        if (Gate::has('viewLogLens')) {
            return Gate::allows('viewLogLens');
        }
        return app()->environment('local');
    }

    /**
     * True when `log-lens.agent_token` is configured and the request presents
     * it, as `X-Log-Lens-Agent-Token` or `Authorization: Bearer <token>`, on
     * an API call. Never matches the dashboard's HTML shell (a request with
     * no `api` parameter) — the token is for a script or agent to call the
     * JSON API, not to browse the UI as a leaked env var. An empty configured
     * token (the default) always returns false, so nothing changes for an
     * app that hasn't set one.
     */
    private static function agentTokenMatches(Request $request): bool
    {
        $configured = trim((string) config('log-lens.agent_token', ''));
        if ($configured === '' || !$request->has('api')) {
            return false;
        }
        $presented = (string) $request->header('X-Log-Lens-Agent-Token', '');
        if ($presented === '') {
            $authorization = (string) $request->header('Authorization', '');
            if (str_starts_with($authorization, 'Bearer ')) {
                $presented = substr($authorization, 7);
            }
        }
        return $presented !== '' && hash_equals($configured, $presented);
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
