<?php

declare(strict_types=1);

namespace LogLens\Laravel\Console;

use Illuminate\Console\Command;
use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\LogImportService;

final class ImportCommand extends Command
{
    protected $signature = 'log-lens:import {paths* : Log files or directories to import} {--app= : Application id}';

    protected $description = 'Import log files into Log Lens (streams paths in place; does not move them).';

    public function handle(): int
    {
        $applications = new ApplicationRegistry((string) config('log-lens.root'));
        $application = $applications->resolve($this->option('app'));
        $database = new Database(
            $applications->absolutePath($application, 'database'),
            (string) $application['id'],
        );

        $result = (new LogImportService($database->pdo))->importPaths($this->argument('paths'));

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ($result['errors'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}
