<?php

declare(strict_types=1);

namespace LogLens\Laravel\Console;

use Illuminate\Console\Command;
use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\LinearSettingsService;
use LogLens\Services\LinearSyncService;

/**
 * Pulls matching Linear issues into a Log Lens application. Schedule it (for
 * example in the console kernel) to keep issues fresh:
 *
 *   $schedule->command('log-lens:linear-sync')->everyFifteenMinutes();
 */
final class LinearSyncCommand extends Command
{
    protected $signature = 'log-lens:linear-sync {--app= : Application id}';

    protected $description = 'Pull matching Linear issues into a Log Lens application.';

    public function handle(): int
    {
        $applications = new ApplicationRegistry((string) config('log-lens.root'));
        $application = $applications->resolve($this->option('app'));
        $database = new Database(
            $applications->absolutePath($application, 'database'),
            (string) $application['id'],
        );
        $settings = new LinearSettingsService($database->pdo);

        if (!$settings->isEnabled()) {
            $this->info('Linear integration is disabled for this application; nothing to do.');
            return self::SUCCESS;
        }

        $result = (new LinearSyncService($database->pdo, $settings))->sync();
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
