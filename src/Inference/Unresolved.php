<?php

namespace G4T\Swagger\Inference;

/**
 * Marker for "this expression is not a compile-time literal" returned by {@see LiteralEvaluator}.
 */
final class Unresolved
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Whether $value (or any nested array value) is unresolved.
     */
    public static function containedIn(mixed $value): bool
    {
        if ($value instanceof self) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containedIn($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
