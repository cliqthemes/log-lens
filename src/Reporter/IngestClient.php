<?php

declare(strict_types=1);

namespace LogLens\Laravel\Reporter;

use LogLens\Database;
use LogLens\Plugins\Ingest\IngestService;
use LogLens\Services\ApplicationRegistry;

/**
 * Delivers captured events to Log Lens. Two drivers:
 *
 *  - `local` (default): write straight into the embedded Log Lens database
 *    in-process — no network, no ingest key. Ideal when the dashboard is
 *    mounted in the same app.
 *  - `http`: POST to a remote Log Lens ingest endpoint with an ingest key.
 *
 * All delivery is best-effort: a monitoring failure must never surface to the
 * app or its users.
 */
final class IngestClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string,mixed> $payload */
    public function send(array $payload): void
    {
        try {
            if (($this->config['driver'] ?? 'local') === 'http') {
                $this->sendHttp($payload);
            } else {
                $this->sendLocal($payload);
            }
        } catch (\Throwable) {
            // Never let error reporting raise its own error.
        }
    }

    /** @param array<string,mixed> $payload */
    private function sendLocal(array $payload): void
    {
        $root = (string) ($this->config['root'] ?? '');
        if ($root === '') {
            return;
        }
        $applications = new ApplicationRegistry($root);
        $application = $applications->resolve((string) ($this->config['application'] ?? 'default'));
        $database = new Database(
            $applications->absolutePath($application, 'database'),
            (string) $application['id'],
        );
        (new IngestService($database->pdo))->ingest($payload);
    }

    /** @param array<string,mixed> $payload */
    private function sendHttp(array $payload): void
    {
        $url = (string) ($this->config['ingest_url'] ?? '');
        $key = (string) ($this->config['key'] ?? '');
        if ($url === '') {
            return;
        }
        // Use the framework's HTTP client (Guzzle) — already present in every
        // Laravel app — for TLS verification, timeouts, and retries for free.
        \Illuminate\Support\Facades\Http::withHeaders(['X-Log-Lens-Ingest-Key' => $key])
            ->timeout((int) ($this->config['timeout'] ?? 4))
            ->connectTimeout(3)
            ->retry(2, 200, throw: false)
            ->post($url, $payload);
    }
}
