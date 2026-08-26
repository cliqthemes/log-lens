<?php

declare(strict_types=1);

namespace LogLens\Laravel\Tests\Feature;

use LogLens\Laravel\Tests\TestCase;

/**
 * End-to-end through the real mounted route and middleware stack (unlike
 * tests/Unit/AuthorizationTest, which calls LogLens::authorized() directly)
 * — this is what actually protects a Laravel-embedded install.
 */
final class RouteAuthorizationTest extends TestCase
{
    public function test_api_request_is_denied_outside_local_with_no_credentials(): void
    {
        $this->app->instance('env', 'production');

        $this->get('/log-lens?api=health')->assertForbidden();
    }

    public function test_api_request_is_open_in_local_with_no_credentials(): void
    {
        $this->app->instance('env', 'local');

        $this->get('/log-lens?api=health')
            ->assertOk()
            ->assertJsonStructure(['checks']);
    }

    public function test_valid_agent_token_reaches_the_api_outside_local(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        $this->withHeaders(['X-Log-Lens-Agent-Token' => 'a-valid-token'])
            ->get('/log-lens?api=health')
            ->assertOk()
            ->assertJsonStructure(['checks']);
    }

    public function test_wrong_agent_token_is_still_denied(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        $this->withHeaders(['X-Log-Lens-Agent-Token' => 'the-wrong-token'])
            ->get('/log-lens?api=health')
            ->assertForbidden();
    }

    public function test_agent_token_does_not_unlock_the_dashboard_shell(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        // No ?api=... — this requests the SPA shell, not the JSON API.
        $this->withHeaders(['X-Log-Lens-Agent-Token' => 'a-valid-token'])
            ->get('/log-lens')
            ->assertForbidden();
    }

    public function test_the_ingest_route_bypasses_the_gate_entirely(): void
    {
        $this->app->instance('env', 'production');

        // No agent token, no auth callback, production env — the dashboard/API
        // route would 403 here (see the first test). This receiver
        // authenticates itself and is mounted outside the gate, so the
        // request reaches the core instead of being blocked by
        // LogLens::authorized() — proven by getting the core's own "HTTP
        // ingest is an opt-in plugin, off by default" 404 rather than a 403.
        $this->postJson('/log-lens/ingest', [])->assertStatus(404);
    }
}
