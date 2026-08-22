<?php

declare(strict_types=1);

namespace LogLens\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogLens\Config;
use LogLens\Laravel\Console\ImportCommand;
use LogLens\Laravel\Console\LinearSyncCommand;
use LogLens\Laravel\Console\SyncCommand;
use LogLens\Laravel\Http\Authorize;
use LogLens\Laravel\Http\LogLensController;
use LogLens\Laravel\Reporter\IngestClient;
use LogLens\Laravel\Reporter\LogLensExceptionHandler;
use LogLens\Laravel\Reporter\LogLensReporter;

final class LogLensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/log-lens.php', 'log-lens');
        $this->registerReporter();
    }

    public function boot(): void
    {
        // Bridge the core engine's configuration from Laravel's config.
        $core = (array) config('log-lens.core', []);
        $core['ingest'] = array_merge(
            ['url' => $this->ingestUrl()],
            (array) ($core['ingest'] ?? []),
        );
        Config::load($core);

        $this->registerRoutes();

        // Flush captured events after the response is sent, so first-hand error
        // reporting never adds latency to the request or command.
        if ((bool) config('log-lens.reporter.enabled', false)) {
            $this->app->terminating(function (): void {
                $this->app->make(LogLensReporter::class)->flush();
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__ . '/../config/log-lens.php' => config_path('log-lens.php')],
                'log-lens-config',
            );
            $this->publishes(
                [__DIR__ . '/../resources/dist' => public_path('vendor/log-lens')],
                'log-lens-assets',
            );
            $this->commands([ImportCommand::class, SyncCommand::class, LinearSyncCommand::class]);
        }
    }

    private function registerReporter(): void
    {
        $this->app->singleton(LogLensReporter::class, function ($app): LogLensReporter {
            $config = (array) config('log-lens.reporter', []);
            $config['root'] = (string) config('log-lens.root');
            return new LogLensReporter($config, new IngestClient($config));
        });

        // Decorate the application's exception handler so every reported
        // exception is also captured by Log Lens, with no changes to the app.
        if ((bool) config('log-lens.reporter.enabled', false)) {
            $this->app->extend(ExceptionHandler::class, function (ExceptionHandler $handler, $app): ExceptionHandler {
                return new LogLensExceptionHandler($handler, $app->make(LogLensReporter::class));
            });
        }
    }

    private function registerRoutes(): void
    {
        if (!(bool) config('log-lens.enabled', true)) {
            return;
        }
        $prefix = (string) config('log-lens.route_prefix', 'log-lens');

        // The dashboard and the whole JSON API: the host app's middleware stack
        // (`web` by default, so sessions + CSRF) plus the access gate.
        Route::group([
            'prefix' => $prefix,
            'middleware' => array_merge((array) config('log-lens.middleware', ['web']), [Authorize::class]),
        ], static function (): void {
            Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/', [LogLensController::class, 'handle']);
        });

        // The two self-authenticating receivers, on their own routes with neither
        // the access gate nor the `web` group. Their callers are external and
        // sessionless — a browser/worker SDK holding an ingest key, and Linear's
        // webhook delivery signing the raw body — so the gate and the CSRF check
        // would reject every legitimate request. Each still proves itself inside
        // the core (ingest key / `Linear-Signature` HMAC), which is exactly why
        // the core exempts them from its own guard.
        Route::group([
            'prefix' => $prefix,
            'middleware' => (array) config('log-lens.receiver-middleware', []),
        ], static function (): void {
            Route::post('ingest', [LogLensController::class, 'ingest']);
            Route::post('linear-webhook', [LogLensController::class, 'linearWebhook']);
        });
    }

    /**
     * The absolute URL of the ingest receiver, handed to the core so Settings →
     * Plugins → HTTP ingest shows the URL that actually works in a Laravel
     * embed. Without it the core would derive `…/log-lens/?api=ingest` from the
     * request, which is on the gated route.
     */
    private function ingestUrl(): string
    {
        if (!function_exists('url')) {
            return '';
        }
        return (string) url((string) config('log-lens.route_prefix', 'log-lens') . '/ingest');
    }
}
