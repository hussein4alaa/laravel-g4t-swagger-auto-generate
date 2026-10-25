<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions\Data;

/**
 * Stand-in for Spatie\LaravelData\Data: typed public properties, created with ::from().
 */
abstract class Data
{
    public static function from(mixed $payload): static
    {
        return new static(...(array) $payload);
    }
}
