<?php

namespace G4T\Swagger\Tests\Fixtures\Enums;

use Illuminate\Validation\Rule;

/**
 * The validation rule for a post status: the PostStatus enum where PHP has enums (8.1+),
 * the same values through Rule::in() on PHP 8.0 (where PostStatus cannot even be loaded).
 */
final class PostStatusRule
{
    public static function make(): object
    {
        return PHP_VERSION_ID >= 80100
            ? Rule::enum(PostStatus::class)
            : Rule::in(['draft', 'published']);
    }
}
