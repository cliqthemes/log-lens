<?php

declare(strict_types=1);

namespace LogLens\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogLens\Http\LogLensRequest;
use LogLens\Identity\Actor;
use LogLens\Kernel;
use RuntimeException;

/**
 * Single entry point mounted at the configured prefix. Mirrors the standalone
 * front controller: serve the SPA shell when there is no `api` parameter,
 * otherwise translate the Illuminate request into the core dispatcher.
 */
final class LogLensController
{
    /**
     * The two actions that authenticate themselves — the HTTP ingest key and the
     * Linear webhook's HMAC over the raw body — and so are exempt from the
     * dashboard's guard in the core. They get their own routes in the Laravel
     * adapter (see LogLensServiceProvider::registerRoutes) because the host's
     * access gate and the `web` group's CSRF check would otherwise reject every
     * external caller: an SDK in a browser page, a headless worker, or Linear's
     * webhook delivery has no session and no CSRF token.
     */
    public function ingest(Request $request): Response|JsonResponse
    {
        return $this->handle($this->withAction($request, 'ingest'));
    }

    public function linearWebhook(Request $request): Response|JsonResponse
    {
        return $this->handle($this->withAction($request, 'linear-webhook'));
    }

    /**
     * Pin the action for a dedicated route. Overwriting rather than defaulting is
     * deliberate: `POST /log-lens/ingest?api=delete-logs` must not reach
     * `delete-logs` through a route that skips the host's access gate.
     */
    private function withAction(Request $request, string $action): Request
    {
        $request->query->set('api', $action);
        return $request;
    }

    public function handle(Request $request): Response|JsonResponse
    {
        if (!$request->has('api')) {
            return $this->shell();
        }

        // Reindexing and large incoming imports can outlast the default request
        // time limit. Connector synchronization is dispatched to a CLI worker.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        // The application this request targets (see ApplicationRegistry) — a raw
        // query value, not yet validated against the registry (Kernel does that);
        // passed through so log-lens.identity/assignable-users can vary per app.
        $applicationId = $request->query('app');

        $core = new LogLensRequest(
            strtoupper($request->method()),
            $request->query(),
            $this->body($request),
            $this->headers($request),
            $request->getContent(),
            $request->url(),
            // Laravel resolves the client IP through the framework's configured
            // trusted proxies, so the per-IP ingest throttle sees the real peer.
            $request->ip(),
            // Server-side identity from the host user (never client headers).
            $this->actor($request, $applicationId),
            $this->assignableUsers($request, $applicationId),
        );

        try {
            $result = (new Kernel((string) config('log-lens.root')))->handle($core);
            return response()->json($result->data, $result->status);
        } catch (\Throwable $exception) {
            // The core dispatcher already turns handled errors into JSON; this is
            // a last resort so a Log Lens route never returns an HTML error page.
            report($exception);
            return response()->json(
                ['error' => 'Unexpected server error. Check the application log.'],
                500,
            );
        }
    }

    private function shell(): Response
    {
        $index = __DIR__ . '/../../resources/dist/index.html';
        if (!is_file($index)) {
            return response(
                "Log Lens UI assets are not built. From the Log Lens repo run:\n"
                . "  cd frontend && npm ci && npm run build:laravel\n"
                . "then in this app: php artisan vendor:publish --tag=log-lens-assets\n",
                503,
            )->header('Content-Type', 'text/plain; charset=utf-8');
        }
        $html = (string) file_get_contents($index);

        // Inject any host-configured request headers so the SPA sends them with
        // every API call (e.g. a bearer token for a custom auth guard).
        $headers = config('log-lens.request_headers', []);
        if (is_callable($headers)) {
            $headers = $headers(request());
        }
        if (is_array($headers) && $headers !== []) {
            $json = json_encode((object) $headers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
            $script = '<script>window.__LOG_LENS_HEADERS__ = ' . $json . ';</script>';
            $html = str_replace('</head>', $script . '</head>', $html);
        }

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        return is_array($body) ? $body : [];
    }

    /** @return array<string,string> */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = is_array($values)
                ? (string) ($values[0] ?? '')
                : (string) $values;
        }
        return $headers;
    }

    /**
     * The Log Lens actor for this request, resolved server-side: a
     * `log-lens.identity` callable if configured, otherwise the authenticated
     * host user with the configured `log-lens.default-role`. Null when
     * unauthenticated (the core then uses its default local owner). Never
     * derived from client-supplied headers.
     *
     * $applicationId lets the callable return different roles per application
     * (C-2) — e.g. a host on spatie/laravel-permission checking a
     * per-team/per-app role rather than one global one. Log Lens itself has
     * no opinion on how the host determines this; it only reads the result.
     *
     * A callable that returns null means "no opinion, use the default". A
     * callable that returns anything else without a usable `id` is a
     * misconfiguration and throws: silently treating it as "no opinion" used to
     * promote the caller to the default role, which is the opposite of what a
     * resolver that meant to *restrict* someone was written to do.
     */
    private function actor(Request $request, ?string $applicationId): ?Actor
    {
        $resolver = config('log-lens.identity');
        if (is_callable($resolver)) {
            $data = $resolver($request, $applicationId);
            if ($data !== null) {
                if (!is_array($data) || (string) ($data['id'] ?? '') === '') {
                    throw new RuntimeException(
                        'log-lens.identity must return null or an array with a non-empty "id"; got '
                        . get_debug_type($data) . '. See config/log-lens.php.'
                    );
                }
                $roles = $this->roles((array) ($data['roles'] ?? []));
                return new Actor(
                    (string) $data['id'],
                    (string) ($data['label'] ?? $data['id']),
                    $roles,
                    'host',
                );
            }
        }

        $user = $request->user();
        if ($user !== null) {
            $id = (string) ($user->getAuthIdentifier() ?? 'user');
            $label = (string) ($user->name ?? $user->email ?? ('User ' . $id));
            return new Actor($id, $label, $this->roles([]), 'host');
        }

        return null;
    }

    /**
     * Normalize a role list, falling back to `log-lens.default-role` when it is
     * empty. Unknown role names are kept as-is — the core's Authorizer grants
     * nothing for them, so a typo fails closed rather than silently widening
     * access.
     *
     * @param  array<array-key,mixed>  $roles
     * @return list<string>
     */
    private function roles(array $roles): array
    {
        $normalized = array_values(array_filter(
            array_map(static fn ($role): string => trim((string) $role), $roles),
            static fn (string $role): bool => $role !== '',
        ));
        if ($normalized !== []) {
            return $normalized;
        }
        $default = trim((string) config('log-lens.default-role', Actor::ROLE_OWNER));
        return [$default !== '' ? $default : Actor::ROLE_OWNER];
    }

    /**
     * Who an issue in this application can be assigned to (C-2): a
     * `log-lens.assignable-users` callable, or empty when unconfigured — Log
     * Lens has no user table of its own, so without this callable there is
     * simply nobody to assign to (the UI's assignee picker stays empty).
     *
     * @return list<array{id:string,label:string}>
     */
    private function assignableUsers(Request $request, ?string $applicationId): array
    {
        $resolver = config('log-lens.assignable-users');
        if (!is_callable($resolver)) {
            return [];
        }
        $users = $resolver($request, $applicationId);
        if (!is_array($users)) {
            return [];
        }
        $result = [];
        foreach ($users as $user) {
            if (is_array($user) && isset($user['id'])) {
                $result[] = ['id' => (string) $user['id'], 'label' => (string) ($user['label'] ?? $user['id'])];
            }
        }
        return $result;
    }
}
