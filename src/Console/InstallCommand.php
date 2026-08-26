<?php

declare(strict_types=1);

namespace LogLens\Laravel\Console;

use Illuminate\Console\Command;
use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\ConnectorService;
use LogLens\Services\LogImportService;
use Throwable;

/**
 * First-run setup, interactive by default: publish the assets/config a plain
 * `composer require` never copies, import whatever is already sitting in
 * storage/logs, offer a connector so new lines keep flowing in, offer the
 * zero-key in-process error reporter, and end with the access-control
 * reminder every embed needs before it leaves local. Every step degrades to
 * "skip and say why" rather than failing the whole command — a partially
 * configured install is still strictly better than none of this having run.
 *
 * Safe to re-run: publishing is idempotent, importing/connecting a directory
 * twice is detected and skipped, and .env is only ever appended to, never
 * rewritten.
 */
final class InstallCommand extends Command
{
    protected $signature = 'log-lens:install {--app= : Application id}';

    protected $description = 'Interactive first-run setup: publish assets, import existing logs, and wire up ongoing sync and error capture.';

    public function handle(): int
    {
        $this->components->info('Setting up Log Lens.');

        $this->publish();

        $applications = new ApplicationRegistry((string) config('log-lens.root'));
        try {
            $application = $applications->resolve($this->option('app'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());
            return self::FAILURE;
        }
        $database = new Database(
            $applications->absolutePath($application, 'database'),
            (string) $application['id'],
        );
        $pdo = $database->pdo;

        $logDirectory = $this->detectLogDirectory();
        if ($logDirectory !== null) {
            $this->components->twoColumnDetail('Detected log directory', $logDirectory);
            $this->maybeImport($pdo, $logDirectory);
            $this->maybeConnect($pdo, $logDirectory);
        } else {
            $this->components->warn(
                "Couldn't find storage/logs — skipping import and connector setup. "
                . 'Run `log-lens:import {path}` manually once you know where your logs live.'
            );
        }

        $this->maybeEnableReporter();
        $this->explainAccessControl();

        $this->newLine();
        $url = function_exists('url') ? (string) url((string) config('log-lens.route_prefix', 'log-lens')) : '';
        $this->components->info('Done.' . ($url !== '' ? " Visit {$url}" : ''));

        return self::SUCCESS;
    }

    private function publish(): void
    {
        $this->call('vendor:publish', ['--tag' => 'log-lens-config']);
        $this->call('vendor:publish', ['--tag' => 'log-lens-assets', '--force' => true]);
    }

    private function detectLogDirectory(): ?string
    {
        // Every stock Laravel log channel (single, daily, or a stack of them)
        // writes under storage/logs regardless of which is active, so this is
        // far more reliable than parsing config('logging.channels').
        $directory = storage_path('logs');
        return is_dir($directory) ? $directory : null;
    }

    private function maybeImport(\PDO $pdo, string $logDirectory): void
    {
        if (!$this->confirm('Import the logs already in that directory now?', true)) {
            return;
        }
        $result = (new LogImportService($pdo))->importPaths([$logDirectory]);
        if (($result['errors'] ?? []) !== []) {
            foreach ($result['errors'] as $error) {
                $this->components->warn($error);
            }
        }
        $this->components->info(sprintf(
            'Imported %d file(s): %d events, %d issue(s).',
            $result['files'] ?? 0,
            $result['events'] ?? 0,
            $result['groups'] ?? 0,
        ));
    }

    private function maybeConnect(\PDO $pdo, string $logDirectory): void
    {
        $service = new ConnectorService($pdo);
        $target = realpath($logDirectory) ?: $logDirectory;
        foreach ($service->all() as $connector) {
            if ($connector['type'] === 'local' && ($connector['config']['directory'] ?? null) === $target) {
                $this->components->twoColumnDetail('Connector', 'already configured for this directory — skipping');
                return;
            }
        }
        if (!$this->confirm('Keep tailing new log lines automatically via a connector? (recommended)', true)) {
            return;
        }
        try {
            $service->create([
                'name' => 'Laravel logs (storage/logs)',
                'type' => 'local',
                'config' => ['directory' => $logDirectory, 'recursive' => true],
            ]);
        } catch (Throwable $exception) {
            $this->components->warn('Could not create the connector: ' . $exception->getMessage());
            return;
        }
        $this->components->info('Connector created. It only fetches new bytes on each sync — schedule it:');
        $this->scheduleSnippet();
    }

    private function scheduleSnippet(): void
    {
        // Laravel 11+ dropped app/Console/Kernel.php in favor of scheduling
        // straight from routes/console.php; show whichever this app actually
        // has, since neither shape should be edited on the user's behalf —
        // it may already be customized, and its exact contents vary too much
        // across Laravel versions to touch blindly.
        if (is_file(base_path('routes/console.php'))) {
            $this->line('  Add to routes/console.php:');
            $this->line("    Schedule::command('log-lens:sync')->everyFiveMinutes();");
        } else {
            $this->line('  Add to app/Console/Kernel.php\'s schedule():');
            $this->line("    \$schedule->command('log-lens:sync')->everyFiveMinutes();");
        }
    }

    private function maybeEnableReporter(): void
    {
        if ((bool) config('log-lens.reporter.enabled', false)) {
            $this->components->twoColumnDetail('Error reporter', 'already enabled');
            return;
        }
        if (!$this->confirm('Automatically capture unhandled exceptions as they happen? (recommended)', true)) {
            return;
        }
        if ($this->putEnv('LOG_LENS_REPORTER', 'true')) {
            $this->components->info('Set LOG_LENS_REPORTER=true in .env (writes straight into this embedded install — no ingest key needed).');
        } else {
            $this->components->warn('LOG_LENS_REPORTER is already set in .env — leaving it as-is.');
        }
    }

    /** Append a key to .env only if it is not already present. Never rewrites an existing value. */
    private function putEnv(string $key, string $value): bool
    {
        $path = base_path('.env');
        if (!is_file($path) || !is_writable($path)) {
            $this->components->warn(".env not found or not writable — add {$key}={$value} yourself.");
            return false;
        }
        $contents = (string) file_get_contents($path);
        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $contents) === 1) {
            return false;
        }
        $needsNewline = $contents !== '' && !str_ends_with($contents, "\n");
        file_put_contents($path, ($needsNewline ? "\n" : '') . "{$key}={$value}\n", FILE_APPEND);
        return true;
    }

    private function explainAccessControl(): void
    {
        $environment = (string) config('app.env', 'production');
        $this->newLine();
        if ($environment === 'local') {
            $this->components->warn(
                "Running in the 'local' environment: the dashboard is open to anyone who can reach this URL, "
                . 'no login required, until you restrict it.'
            );
        } else {
            $this->components->info(
                "Running in '{$environment}': the dashboard is denied to everyone by default until you restrict it."
            );
        }
        $this->line('  Restrict access in a service provider\'s boot():');
        $this->line("    use LogLens\\Laravel\\LogLens;");
        $this->line("    LogLens::auth(fn (\$request) => \$request->user()?->can('view-logs') ?? false);");
        $this->line('  See "Authorization" in the Log Lens Laravel package docs for every option.');

        $this->maybeGenerateAgentToken();
    }

    /**
     * Opt-in, not recommended-by-default like the earlier prompts — this
     * hands out a standing credential, so it should only be created when the
     * user actually wants a script or agent (e.g. the bundled Claude/Codex
     * skill) to reach the API outside a browser session.
     */
    private function maybeGenerateAgentToken(): void
    {
        if (trim((string) config('log-lens.agent_token', '')) !== '') {
            $this->components->twoColumnDetail('Agent token', 'already set — leaving it as-is');
            return;
        }
        if (!$this->confirm(
            'Generate an agent token, so a script or coding agent (e.g. the bundled issue-fixing skill) can reach the API without a login? (optional)',
            false,
        )) {
            return;
        }
        $token = bin2hex(random_bytes(20));
        if (!$this->putEnv('LOG_LENS_AGENT_TOKEN', $token)) {
            $this->components->warn('LOG_LENS_AGENT_TOKEN is already set in .env — leaving it as-is.');
            return;
        }
        $this->components->info("Set LOG_LENS_AGENT_TOKEN in .env: {$token}");
        $this->line('  Send it as `X-Log-Lens-Agent-Token: <token>` on API requests (?api=...) — never grants the dashboard shell.');
        $this->line('  It carries the `default-role` above (owner by default) — rotate by changing the value; there is nothing else to revoke.');
    }
}
