<?php

declare(strict_types=1);

namespace LogLens\Laravel\Reporter;

use Throwable;

/**
 * Captures exceptions and messages first-hand and buffers them for delivery.
 * Events are flushed once per request/command on `terminating`, so reporting
 * never adds latency to the response.
 */
final class LogLensReporter
{
    /** Safety valve: flush early so a long-lived worker (Octane/queue) that
     *  never "terminates" per request cannot grow the buffer without bound. */
    private const MAX_BUFFER = 50;

    /** @var list<array<string,mixed>> */
    private array $buffer = [];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config, private readonly IngestClient $client)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function capture(Throwable $exception): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->buffer[] = array_filter([
            'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            'exception_class' => $exception::class,
            'severity' => 'ERROR',
            'stack' => $this->stack($exception),
            'channel' => 'laravel',
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->config['release'] ?? null,
            'server_name' => gethostname() ?: null,
            'request' => $this->requestContext(),
            'user' => $this->userContext(),
        ], static fn ($value): bool => $value !== null && $value !== []);
        $this->flushIfFull();
    }

    public function captureMessage(string $message, string $severity = 'INFO'): void
    {
        if (!$this->enabled() || trim($message) === '') {
            return;
        }
        $this->buffer[] = array_filter([
            'message' => $message,
            'severity' => strtoupper($severity),
            'channel' => 'laravel',
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->config['release'] ?? null,
            'request' => $this->requestContext(),
            'user' => $this->userContext(),
        ], static fn ($value): bool => $value !== null && $value !== []);
        $this->flushIfFull();
    }

    private function flushIfFull(): void
    {
        if (count($this->buffer) >= self::MAX_BUFFER) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $events = $this->buffer;
        $this->buffer = [];
        $this->client->send(['events' => $events]);
    }

    private function stack(Throwable $exception): string
    {
        // A leading "#0 file(line)" frame the parser recognizes, then the trace.
        return sprintf("#0 %s(%d): thrown\n%s", $exception->getFile(), $exception->getLine(), $exception->getTraceAsString());
    }

    /** @return array<string,mixed>|null */
    private function requestContext(): ?array
    {
        if (!function_exists('request')) {
            return null;
        }
        try {
            $request = request();
            if ($request === null || (function_exists('app') && app()->runningInConsole())) {
                return null;
            }
            return array_filter([
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'route' => optional($request->route())->getName(),
                'ip' => $request->ip(),
            ], static fn ($value): bool => $value !== null && $value !== '');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function userContext(): ?array
    {
        try {
            if (!function_exists('auth') || !auth()->check()) {
                return null;
            }
            $user = auth()->user();
            return array_filter([
                'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
                'email' => $user->email ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');
        } catch (\Throwable) {
            return null;
        }
    }
}
