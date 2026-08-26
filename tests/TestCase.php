<?php

declare(strict_types=1);

namespace LogLens\Laravel\Tests;

use LogLens\Laravel\LogLens;
use LogLens\Laravel\LogLensServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ReflectionProperty;

/**
 * Every test gets a fresh Laravel application (Testbench's default per-test
 * behavior) with the package registered and its config pointed at a private
 * temp workspace, plus a reset of LogLens's one process-wide static (the
 * LogLens::auth() callback) so a callback registered in one test can never
 * leak into the next.
 */
abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LogLensServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('log-lens.root', $this->tempWorkspace());
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The Testbench skeleton app lives on disk and is shared across every
        // test method (only the container is rebuilt per test), so a
        // storage/logs directory created by one test would otherwise still be
        // there for the next one. Start every test from a clean slate.
        $this->forgetStorageLogsDirectory();
    }

    private function forgetStorageLogsDirectory(): void
    {
        $directory = storage_path('logs');
        if (!is_dir($directory)) {
            return;
        }
        // GLOB_DOT matters here: the Testbench skeleton ships storage/logs
        // with only a dotfile (.gitignore) in it, same as any real Laravel
        // app — a plain glob('*') would leave it behind and rmdir() would
        // then silently fail on the non-empty directory.
        foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($directory);
    }

    protected function tempWorkspace(): string
    {
        $path = sys_get_temp_dir() . '/log-lens-laravel-tests-' . bin2hex(random_bytes(8));
        mkdir($path, 0755, true);
        return $path;
    }

    protected function tearDown(): void
    {
        $this->resetAuthCallback();
        parent::tearDown();
    }

    private function resetAuthCallback(): void
    {
        $property = new ReflectionProperty(LogLens::class, 'authCallback');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
