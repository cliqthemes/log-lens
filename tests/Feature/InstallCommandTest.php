<?php

declare(strict_types=1);

namespace LogLens\Laravel\Tests\Feature;

use LogLens\Laravel\Tests\TestCase;
use LogLens\Services\ConnectorService;

/**
 * `log-lens:install` (packages/laravel/src/Console/InstallCommand.php) had no
 * coverage at all before this: every step degrades to "skip and say why"
 * rather than failing the command, which is exactly the kind of branching
 * that silently rots without a test walking each path.
 */
final class InstallCommandTest extends TestCase
{
    public function test_completes_successfully_declining_every_optional_step(): void
    {
        // No storage/logs directory in the skeleton app, so import/connector
        // are skipped entirely and only the reporter/agent-token prompts fire.
        $this->artisan('log-lens:install')
            ->expectsQuestion('Automatically capture unhandled exceptions as they happen? (recommended)', false)
            ->expectsQuestion(
                'Generate an agent token, so a script or coding agent (e.g. the bundled issue-fixing skill) can reach the API without a login? (optional)',
                false,
            )
            ->assertExitCode(0);

        $this->assertFalse((bool) config('log-lens.reporter.enabled'));
        $this->assertSame('', config('log-lens.agent_token'));
    }

    public function test_creates_a_connector_for_an_existing_log_directory(): void
    {
        $logDirectory = storage_path('logs');
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0755, true);
        }

        $this->artisan('log-lens:install')
            ->expectsQuestion('Import the logs already in that directory now?', false)
            ->expectsQuestion('Keep tailing new log lines automatically via a connector? (recommended)', true)
            ->expectsQuestion('Automatically capture unhandled exceptions as they happen? (recommended)', false)
            ->expectsQuestion(
                'Generate an agent token, so a script or coding agent (e.g. the bundled issue-fixing skill) can reach the API without a login? (optional)',
                false,
            )
            ->assertExitCode(0);

        $pdo = new \PDO('sqlite:' . config('log-lens.root') . '/storage/log-analyzer.sqlite');
        $connectors = (new ConnectorService($pdo))->all();
        $this->assertNotEmpty($connectors);
        $this->assertSame('local', $connectors[0]['type']);
    }

    public function test_re_running_the_connector_step_does_not_duplicate_it(): void
    {
        $logDirectory = storage_path('logs');
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0755, true);
        }

        $this->artisan('log-lens:install')
            ->expectsQuestion('Import the logs already in that directory now?', false)
            ->expectsQuestion('Keep tailing new log lines automatically via a connector? (recommended)', true)
            ->expectsQuestion('Automatically capture unhandled exceptions as they happen? (recommended)', false)
            ->expectsQuestion(
                'Generate an agent token, so a script or coding agent (e.g. the bundled issue-fixing skill) can reach the API without a login? (optional)',
                false,
            )
            ->assertExitCode(0);

        // Second run: the connector already exists for this directory, so
        // InstallCommand::maybeConnect() skips the question entirely instead
        // of asking again — asserted by NOT calling expectsQuestion() for it.
        $this->artisan('log-lens:install')
            ->expectsQuestion('Import the logs already in that directory now?', false)
            ->expectsQuestion('Automatically capture unhandled exceptions as they happen? (recommended)', false)
            ->expectsQuestion(
                'Generate an agent token, so a script or coding agent (e.g. the bundled issue-fixing skill) can reach the API without a login? (optional)',
                false,
            )
            ->assertExitCode(0);

        $pdo = new \PDO('sqlite:' . config('log-lens.root') . '/storage/log-analyzer.sqlite');
        $connectors = (new ConnectorService($pdo))->all();
        $this->assertCount(1, $connectors);
    }

    public function test_writes_the_reporter_and_agent_token_env_vars_when_accepted(): void
    {
        $envPath = base_path('.env');
        $originallyExisted = is_file($envPath);
        $original = $originallyExisted ? (string) file_get_contents($envPath) : null;
        file_put_contents($envPath, "APP_NAME=Testbench\n");

        try {
            $this->artisan('log-lens:install')
                ->expectsQuestion('Automatically capture unhandled exceptions as they happen? (recommended)', true)
                ->expectsQuestion(
                    'Generate an agent token, so a script or coding agent (e.g. the bundled issue-fixing skill) can reach the API without a login? (optional)',
                    true,
                )
                ->assertExitCode(0);

            $contents = (string) file_get_contents($envPath);
            $this->assertMatchesRegularExpression('/^LOG_LENS_REPORTER=true$/m', $contents);
            $this->assertMatchesRegularExpression('/^LOG_LENS_AGENT_TOKEN=[0-9a-f]{40}$/m', $contents);
        } finally {
            if ($originallyExisted) {
                file_put_contents($envPath, (string) $original);
            } else {
                @unlink($envPath);
            }
        }
    }
}
