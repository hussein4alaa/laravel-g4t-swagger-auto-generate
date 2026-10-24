<?php

namespace G4T\Swagger\Support;

use Throwable;

/**
 * Generation must never break the docs, but swallowed errors should still be visible while debugging.
 */
final class Debug
{
    public static function log(string $message, ?Throwable $exception = null, array $context = []): void
    {
        try {
            if (! config('app.debug')) {
                return;
            }

            if ($exception !== null) {
                $context['exception'] = $exception;
            }

            logger()->debug('g4t/swagger: '.$message, $context);
        } catch (Throwable) {
            // Logging must never break documentation generation.
        }
    }
}
