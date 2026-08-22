<?php

declare(strict_types=1);

namespace LogLens\Laravel\Reporter;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Decorates the application's exception handler so every reported exception is
 * also captured by Log Lens — zero changes to the app's own Handler. All other
 * behavior (rendering, shouldReport, console output) delegates untouched, and a
 * failure inside capture never affects normal error handling.
 */
final class LogLensExceptionHandler implements ExceptionHandler
{
    public function __construct(
        private readonly ExceptionHandler $inner,
        private readonly LogLensReporter $reporter,
    ) {
    }

    public function report(Throwable $e): void
    {
        if ($this->inner->shouldReport($e)) {
            try {
                $this->reporter->capture($e);
            } catch (\Throwable) {
                // Reporting must never break the real handler.
            }
        }
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
