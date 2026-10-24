<?php

namespace G4T\Swagger\Support;

/**
 * Settings saved from the dashboard, layered over config/swagger.php.
 *
 * The file (storage/app/swagger/settings.json) only holds what was changed in the dashboard:
 * `config` (overridden swagger.* values) and `layout` (tag / operation order and hidden
 * operations). Keys that were never changed keep following the config file.
 */
final class Settings
{
    /** swagger.* keys the dashboard may override. */
    public const EDITABLE = [
        'title', 'description', 'version', 'email',
        'enable', 'enable_auth', 'username', 'password',
        'ui', 'theme', 'g4t', 'scalar',
        'versions', 'default', 'servers',
        'security_schemes', 'global_headers', 'auto_login',
        'infer_response_examples', 'infer_error_response_examples', 'response_schemas',
        'unwrap_resource_collection_examples', 'include_web_routes', 'show_prefix', 'spatie_query_builder',
    ];

    /** Keys whose value is an associative array merged over the config file value (one level). */
    private const MERGED = ['g4t', 'scalar', 'auto_login'];

    /** @var array{config: array<string, mixed>, layout: array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>}}|null */
    private static ?array $data = null;

    /** @var array<string, mixed>|null swagger.* before the dashboard overrides were applied */
    private static ?array $baseline = null;

    public static function path(): string
    {
        return storage_path('app/swagger/settings.json');
    }

    /**
     * Applies the saved overrides to config('swagger'); called once while the package boots.
     */
    public static function apply(): void
    {
        self::$baseline = (array) config('swagger', []);
        foreach (self::overrides() as $key => $value) {
            config()->set('swagger.'.$key, self::merged($key, $value, self::$baseline[$key] ?? null));
        }
    }

    /**
     * swagger.* as the config file (and package defaults) define it, without dashboard overrides.
     *
     * @return array<string, mixed>
     */
    public static function baseline(): array
    {
        return self::$baseline ?? (array) config('swagger', []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function overrides(): array
    {
        return self::data()['config'];
    }

    /**
     * @return array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>}
     */
    public static function layout(): array
    {
        return self::data()['layout'];
    }

    /**
     * Stores the given effective values: only keys that differ from the config file are kept.
     *
     * @param  array<string, mixed>  $values  editable swagger.* keys
     * @param  array{tags?: list<string>, operations?: array<string, list<string>>, hidden?: list<string>}  $layout
     */
    public static function save(array $values, array $layout): bool
    {
        $baseline = self::baseline();
        $overrides = [];
        foreach (self::EDITABLE as $key) {
            if (! array_key_exists($key, $values)) {
                if (array_key_exists($key, self::overrides())) {
                    $overrides[$key] = self::overrides()[$key];
                }

                continue;
            }
            $effective = self::merged($key, $values[$key], $baseline[$key] ?? null);
            if (self::normalize($effective) !== self::normalize($baseline[$key] ?? null)) {
                $overrides[$key] = $values[$key];
            }
        }

        $data = ['config' => $overrides, 'layout' => self::cleanLayout($layout)];
        if (! self::write($data)) {
            return false;
        }

        self::$data = $data;
        foreach (self::EDITABLE as $key) {
            config()->set('swagger.'.$key, array_key_exists($key, $overrides) ? self::merged($key, $overrides[$key], $baseline[$key] ?? null) : ($baseline[$key] ?? null));
        }
        CachedSpec::clear();

        return true;
    }

    /**
     * Forgets everything saved from the dashboard.
     */
    public static function reset(): bool
    {
        $path = self::path();
        if (is_file($path) && ! unlink($path)) {
            return false;
        }

        foreach (self::EDITABLE as $key) {
            config()->set('swagger.'.$key, self::baseline()[$key] ?? null);
        }
        self::$data = null;
        CachedSpec::clear();

        return true;
    }

    /** Clears the in-memory copy (tests, long-running workers). */
    public static function flush(): void
    {
        self::$data = null;
        self::$baseline = null;
    }

    /**
     * @return array{config: array<string, mixed>, layout: array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>}}
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $raw = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : null;
        $config = is_array($raw['config'] ?? null) ? array_intersect_key($raw['config'], array_flip(self::EDITABLE)) : [];

        return self::$data = [
            'config' => $config,
            'layout' => self::cleanLayout(is_array($raw['layout'] ?? null) ? $raw['layout'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>}
     */
    private static function cleanLayout(array $layout): array
    {
        $strings = fn ($list) => is_array($list) ? array_values(array_unique(array_filter($list, 'is_string'))) : [];
        $operations = [];
        foreach ((array) ($layout['operations'] ?? []) as $tag => $ids) {
            if (is_string($tag)) {
                $operations[$tag] = $strings($ids);
            }
        }

        return ['tags' => $strings($layout['tags'] ?? []), 'operations' => $operations, 'hidden' => $strings($layout['hidden'] ?? [])];
    }

    private static function merged(string $key, mixed $value, mixed $base): mixed
    {
        if (in_array($key, self::MERGED, true) && is_array($value) && is_array($base)) {
            return array_replace($base, $value);
        }

        return $value;
    }

    /**
     * Comparable form. Order is kept (it matters for versions, schemes, headers…); booleans
     * and numbers coming from env() strings compare by value.
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'normalize'], $value);
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function write(array $data): bool
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        // Atomic: write a temporary file, then rename over the old one.
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return false;
        }

        return rename($tmp, $path);
    }
}
