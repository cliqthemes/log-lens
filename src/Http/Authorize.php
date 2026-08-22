<?php

declare(strict_types=1);

namespace LogLens\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use LogLens\Laravel\LogLens;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gates every Log Lens route through LogLens::authorized(), which defers to the
 * host app's registered callback (or local-only access by default).
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!LogLens::authorized($request)) {
            throw new AccessDeniedHttpException('Not authorized to access Log Lens.');
        }
        return $next($request);
    }
}
