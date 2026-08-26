<?php

declare(strict_types=1);

namespace LogLens\Laravel\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogLens\Laravel\LogLens;
use LogLens\Laravel\Tests\TestCase;

/**
 * LogLens::authorized() decides access in a fixed order (see its docblock):
 * an agent token, then a registered auth callback, then a `viewLogLens`
 * Gate, then the `local`-environment default. These tests exercise each
 * rule and the precedence between them directly, without going through HTTP
 * routing/middleware (see tests/Feature for that).
 */
final class AuthorizationTest extends TestCase
{
    public function test_denies_by_default_outside_local(): void
    {
        $this->app->instance('env', 'production');

        $this->assertFalse(LogLens::authorized(Request::create('/log-lens')));
    }

    public function test_allows_by_default_in_local(): void
    {
        $this->app->instance('env', 'local');

        $this->assertTrue(LogLens::authorized(Request::create('/log-lens')));
    }

    public function test_auth_callback_overrides_the_local_default(): void
    {
        $this->app->instance('env', 'local');
        LogLens::auth(fn (Request $request) => false);

        $this->assertFalse(LogLens::authorized(Request::create('/log-lens')));
    }

    public function test_auth_callback_can_grant_access_outside_local(): void
    {
        $this->app->instance('env', 'production');
        LogLens::auth(fn (Request $request) => true);

        $this->assertTrue(LogLens::authorized(Request::create('/log-lens')));
    }

    public function test_gate_is_consulted_when_no_callback_is_registered(): void
    {
        $this->app->instance('env', 'production');
        Gate::define('viewLogLens', fn (?\stdClass $user = null) => true);

        $this->assertTrue(LogLens::authorized(Request::create('/log-lens')));
    }

    public function test_callback_takes_precedence_over_the_gate(): void
    {
        $this->app->instance('env', 'production');
        Gate::define('viewLogLens', fn (?\stdClass $user = null) => true);
        LogLens::auth(fn (Request $request) => false);

        $this->assertFalse(LogLens::authorized(Request::create('/log-lens')));
    }

    public function test_agent_token_grants_access_on_an_api_request_outside_local(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        $request = Request::create('/log-lens', 'GET', ['api' => 'summary'], [], [], [
            'HTTP_X_LOG_LENS_AGENT_TOKEN' => 'a-valid-token',
        ]);

        $this->assertTrue(LogLens::authorized($request));
    }

    public function test_agent_token_is_accepted_as_a_bearer_header(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        $request = Request::create('/log-lens', 'GET', ['api' => 'summary'], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer a-valid-token',
        ]);

        $this->assertTrue(LogLens::authorized($request));
    }

    public function test_agent_token_mismatch_is_rejected(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        $request = Request::create('/log-lens', 'GET', ['api' => 'summary'], [], [], [
            'HTTP_X_LOG_LENS_AGENT_TOKEN' => 'the-wrong-token',
        ]);

        $this->assertFalse(LogLens::authorized($request));
    }

    public function test_agent_token_never_grants_the_dashboard_shell(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);

        // No ?api=... query parameter — this is the SPA shell, not an API call.
        $request = Request::create('/log-lens', 'GET', [], [], [], [
            'HTTP_X_LOG_LENS_AGENT_TOKEN' => 'a-valid-token',
        ]);

        $this->assertFalse(LogLens::authorized($request));
    }

    public function test_an_unconfigured_agent_token_never_matches(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => '']);

        $request = Request::create('/log-lens', 'GET', ['api' => 'summary'], [], [], [
            'HTTP_X_LOG_LENS_AGENT_TOKEN' => 'anything-at-all',
        ]);

        $this->assertFalse(LogLens::authorized($request));
    }

    public function test_agent_token_is_checked_before_a_callback_that_would_otherwise_deny(): void
    {
        $this->app->instance('env', 'production');
        config(['log-lens.agent_token' => 'a-valid-token']);
        // A session-based callback a sessionless caller could never satisfy.
        LogLens::auth(fn (Request $request) => $request->user() !== null);

        $request = Request::create('/log-lens', 'GET', ['api' => 'summary'], [], [], [
            'HTTP_X_LOG_LENS_AGENT_TOKEN' => 'a-valid-token',
        ]);

        $this->assertTrue(LogLens::authorized($request));
    }
}
