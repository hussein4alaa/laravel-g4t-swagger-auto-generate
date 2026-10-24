<?php

namespace G4T\Swagger\Inference;

/**
 * Name-based placeholder values used when a value cannot be derived from code, casts or literals.
 */
final class ExampleGuesser
{
    public const DATETIME = '2024-01-01T12:00:00.000000Z';

    public const URL = 'https://example.com';

    /** Example for an array/json value whose keys are unknown. */
    public const OBJECT_PLACEHOLDER = ['key' => 'value'];

    private const LIST_NAMES = [
        'tags', 'gallery', 'images', 'photos', 'files', 'ids', 'roles', 'permissions', 'items', 'list', 'values',
    ];

    private const OBJECT_NAMES = [
        'dimensions', 'settings', 'options', 'attributes', 'details', 'metadata', 'meta', 'address', 'config',
        'configuration', 'preferences', 'data', 'payload', 'properties', 'extra', 'extras', 'coordinates',
        'location', 'specs', 'specifications', 'translations', 'info', 'context', 'labels', 'pivot',
    ];

    /**
     * Example for a property / array key from its name.
     */
    public static function property(string $name): mixed
    {
        $lower = strtolower($name);

        if ($lower === 'id' || str_ends_with($lower, '_id')) {
            return 1;
        }

        if ($lower === 'uuid' || str_ends_with($lower, '_uuid')) {
            return '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
        }

        if (str_contains($lower, 'email')) {
            return 'user@example.com';
        }

        if (str_ends_with($lower, '_at') || $lower === 'date' || str_contains($lower, 'time')) {
            return self::DATETIME;
        }

        if (str_contains($lower, 'name') || $lower === 'title' || $lower === 'slug') {
            return 'Example';
        }

        if (str_contains($lower, 'url') || str_contains($lower, 'link')) {
            return self::URL;
        }

        if (str_contains($lower, 'token')) {
            return '1|aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789';
        }

        if (str_contains($lower, 'count') || str_contains($lower, 'total') || str_contains($lower, 'number')) {
            return 0;
        }

        if (str_contains($lower, 'active') || str_contains($lower, 'enabled') || str_starts_with($lower, 'is_') || str_starts_with($lower, 'has_')) {
            return true;
        }

        return 'string';
    }

    /**
     * Keys that usually map to integers in API payloads (counts, pagination, etc.).
     */
    public static function keySuggestsNumber(string $key): bool
    {
        $k = strtolower($key);
        if (str_ends_with($k, '_count')) {
            return true;
        }

        return in_array($k, [
            'total', 'read', 'unread', 'count', 'size', 'length', 'sum', 'min', 'max',
            'offset', 'limit', 'page', 'per_page', 'remaining', 'pending', 'failed',
            'success', 'active', 'inactive',
        ], true);
    }

    /**
     * Example for an array/json attribute: a JSON list for list-like names, a JSON object otherwise.
     *
     * @return array<int|string, string>
     */
    public static function collection(string $name): array
    {
        return self::looksLikeList($name) ? ['string'] : self::OBJECT_PLACEHOLDER;
    }

    private static function looksLikeList(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::LIST_NAMES as $list) {
            if ($lower === $list || str_ends_with($lower, '_' . $list)) {
                return true;
            }
        }
        foreach (self::OBJECT_NAMES as $object) {
            if ($lower === $object || str_ends_with($lower, '_' . $object)) {
                return false;
            }
        }

        return str_ends_with($lower, 's') && ! str_ends_with($lower, 'ss');
    }
}
