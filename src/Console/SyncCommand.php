<?php

declare(strict_types=1);

namespace LogLens\Laravel\Console;

use Illuminate\Console\Command;
use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\ConnectorSyncService;

final class SyncCommand extends Command
{
    protected $signature = 'log-lens:sync {--app= : Application id} {--connector= : Sync only this connector id}';

    protected $description = 'Synchronize log connectors for a Log Lens application.';

    public function handle(): int
    {
        $applications = new ApplicationRegistry((string) config('log-lens.root'));
        $application = $applications->resolve($this->option('app'));
        $database = new Database(
            $applications->absolutePath($application, 'database'),
            (string) $application['id'],
        );
        $service = new ConnectorSyncService(
            $database->pdo,
            $applications->absolutePath($application, 'sources'),
        );

        $connectorId = (int) $this->option('connector');
        $queued = $service->queuedRunIds($connectorId > 0 ? $connectorId : null);
        $result = $queued !== []
            ? $service->drainQueued($connectorId > 0 ? $connectorId : null)
            : ($connectorId > 0 ? $service->sync($connectorId) : $service->syncAll());

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return (isset($result['errors']) && $result['errors'] !== []) ? self::FAILURE : self::SUCCESS;
    }
}
