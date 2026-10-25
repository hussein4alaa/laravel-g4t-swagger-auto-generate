<?php

namespace G4T\Swagger\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Settings saved from the dashboard, layered over config/swagger.php.
 *
 * The file (swagger-settings.json in the project root by default, or on a filesystem disk such as s3 with
 * `dashboard.settings_disk`) only holds what was changed in the dashboard:
 * `config` (overridden swagger.* values), `links` (partner links) and `layout` (tag / operation order, hidden operations
 * and controllers, the English / Arabic texts of the info block, tags and operations, and the status /
 * note of each operation). Keys that were never changed keep following the config file.
 */
final class Settings
{
    /** swagger.* keys the dashboard may override. */
    public const EDITABLE = [
        'title', 'description', 'version', 'email',
        'enable', 'enable_auth', 'username', 'password',
        'ui', 'theme', 'g4t', 'scalar',
        'versions', 'default', 'servers',
        'security_schemes', 'auth_middlewares', 'global_headers', 'environments', 'auto_login',
        'infer_response_examples', 'infer_error_response_examples', 'response_schemas',
        'unwrap_resource_collection_examples', 'include_web_routes', 'show_prefix', 'spatie_query_builder',
    ];

    /** Languages the dashboard writes texts in; English replaces the generated text. */
    public const LANGUAGES = ['en', 'ar'];

    /** Statuses an operation can be marked with; "stable" clears one set in code. */
    public const STATUSES = ['stable', 'new', 'beta', 'deprecated'];

    /** Keys whose value is an associative array merged over the config file value (one level). */
    private const MERGED = ['g4t', 'scalar', 'auto_login'];

    /** @var array{config: array<string, mixed>, links: list<array<string, mixed>>, layout: array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>, hidden_tags: list<string>, texts: array<string, mixed>, meta: array<string, mixed>}}|null */
    private static ?array $data = null;

    /** @var array<string, mixed>|null swagger.* before the dashboard overrides were applied */
    private static ?array $baseline = null;

    /** @var string|false|null the disk named in the local file ({"storage": {"disk": "s3"}}); false until read */
    private static string|false|null $pointer = false;

    public static function path(): string
    {
        return self::disk() !== null ? self::disk().':'.self::diskPath() : self::localPath();
    }

    /** The local file: the settings themselves, or only a pointer to the disk that holds them. */
    public static function localPath(): string
    {
        $path = (string) (config('swagger.dashboard.settings_path') ?: 'swagger-settings.json');

        return preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $path) ? $path : base_path($path);
    }

    /**
     * The filesystem disk the settings live on (`s3`, …), shared by every server or pod; null for the local file.
     * `dashboard.settings_disk` wins; otherwise the disk chosen in the dashboard, which the local file points to.
     */
    public static function disk(): ?string
    {
        return self::configuredDisk() ?? self::pointedDisk();
    }

    /** The disk set in the config file or .env; the dashboard cannot change it. */
    public static function configuredDisk(): ?string
    {
        $disk = config('swagger.dashboard.settings_disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    private static function pointedDisk(): ?string
    {
        if (self::$pointer === false) {
            $raw = is_file(self::localPath()) ? json_decode((string) file_get_contents(self::localPath()), true) : null;
            $disk = $raw['storage']['disk'] ?? null;
            self::$pointer = is_string($disk) && $disk !== '' ? $disk : null;
        }

        return self::$pointer;
    }

    /**
     * Where the settings are and where they can go, for the dashboard.
     *
     * @return array{disk: ?string, locked: bool, path: string, local: string, disks: list<string>}
     */
    public static function storage(): array
    {
        return [
            'disk' => self::disk(),
            'locked' => self::configuredDisk() !== null,
            'path' => self::path(),
            'local' => self::localPath(),
            'disks' => array_values(array_map('strval', array_keys((array) config('filesystems.disks', [])))),
        ];
    }

    /**
     * Moves the saved settings to a disk ($disk) or back to the local file (null). The disk gets the settings
     * and the local file only a pointer to it, so commit that small file (or set dashboard.settings_disk) to
     * have every server read the same disk. Nothing moves when the write cannot be read back.
     *
     * @return string|null null when moved, else why not
     */
    public static function moveTo(?string $disk): ?string
    {
        if (self::configuredDisk() !== null) {
            return 'The disk is set by dashboard.settings_disk (SWAGGER_DASHBOARD_SETTINGS_DISK).';
        }
        if ($disk !== null && ! array_key_exists($disk, (array) config('filesystems.disks', []))) {
            return "There is no \"{$disk}\" disk in config/filesystems.php.";
        }
        $json = self::encode(self::data());
        if ($json === null) {
            return 'The settings could not be encoded.';
        }

        if ($disk === null) {
            if (! self::writeLocal($json)) {
                return 'Could not write '.self::localPath().'.';
            }
        } else {
            try {
                $storage = Storage::disk($disk);
                if (! $storage->put(self::diskPath(), $json, ['visibility' => 'private']) || $storage->get(self::diskPath()) !== $json) {
                    return "Could not write and read back the settings on the \"{$disk}\" disk.";
                }
            } catch (Throwable $e) {
                Debug::log("could not move the settings to the {$disk} disk", $e);

                return self::diskFailure($disk, $e);
            }
            if (! self::writeLocal(self::encode(['storage' => ['disk' => $disk]]) ?? '')) {
                return 'The settings are on the disk, but '.self::localPath().' could not point to it.';
            }
        }

        self::$pointer = $disk;
        if ($disk !== null) {
            self::cached(fn () => self::cache()->put(self::cacheKey(), $json, self::cacheSeconds()));
        }

        return null;
    }

    /** The file's path on the disk. */
    private static function diskPath(): string
    {
        return ltrim((string) (config('swagger.dashboard.settings_path') ?: 'swagger-settings.json'), '/\\');
    }

    /** Where releases before the configurable path saved the settings; read until the next save. */
    public static function legacyPath(): string
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
     * @return array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>, hidden_tags: list<string>, texts: array<string, mixed>, meta: array<string, mixed>}
     */
    public static function layout(): array
    {
        return self::data()['layout'];
    }

    /**
     * Stores the given effective values: only keys that differ from the config file are kept.
     *
     * @param  array<string, mixed>  $values  editable swagger.* keys
     * @param  array{tags?: list<string>, operations?: array<string, list<string>>, hidden?: list<string>, hidden_tags?: list<string>, texts?: array<string, mixed>, meta?: array<string, mixed>}  $layout
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

        $data = ['config' => $overrides, 'layout' => self::cleanLayout($layout), 'links' => self::links()];
        if (! self::write($data)) {
            return false;
        }

        self::$data = $data;
        if (self::disk() === null && self::legacyPath() !== self::localPath() && is_file(self::legacyPath())) {
            @unlink(self::legacyPath());
        }
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
        if (! self::delete()) {
            return false;
        }

        foreach (self::EDITABLE as $key) {
            config()->set('swagger.'.$key, self::baseline()[$key] ?? null);
        }
        self::$data = null;
        CachedSpec::clear();

        return true;
    }

    /**
     * Partner links ({@see PartnerLinks}); only the sha256 of each token is kept.
     *
     * @return list<array{id: string, name: string, hash: string, secret: ?string, tags: list<string>, operations: list<string>, security: ?list<string>, versions: ?list<string>, environments: ?list<string>, expires: ?string, created: ?string}>
     */
    public static function links(): array
    {
        return self::data()['links'];
    }

    /**
     * @param  list<array<string, mixed>>  $links
     */
    public static function saveLinks(array $links): bool
    {
        $data = self::data();
        $data['links'] = self::cleanLinks($links);
        if (! self::write($data)) {
            return false;
        }
        self::$data = $data;

        return true;
    }

    /** Clears the in-memory copy (tests, long-running workers). */
    public static function flush(): void
    {
        self::$data = null;
        self::$pointer = false;
        self::$baseline = null;
    }

    /**
     * @return array{config: array<string, mixed>, links: list<array<string, mixed>>, layout: array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>, hidden_tags: list<string>, texts: array<string, mixed>, meta: array<string, mixed>}}
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $contents = self::read();
        $raw = $contents !== null ? json_decode($contents, true) : null;
        $config = is_array($raw['config'] ?? null) ? array_intersect_key($raw['config'], array_flip(self::EDITABLE)) : [];

        return self::$data = [
            'config' => $config,
            'layout' => self::cleanLayout(is_array($raw['layout'] ?? null) ? $raw['layout'] : []),
            'links' => self::cleanLinks(is_array($raw['links'] ?? null) ? $raw['links'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{tags: list<string>, operations: array<string, list<string>>, hidden: list<string>, hidden_tags: list<string>, texts: array<string, mixed>, meta: array<string, mixed>}
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

        return [
            'tags' => $strings($layout['tags'] ?? []),
            'operations' => $operations,
            'hidden' => $strings($layout['hidden'] ?? []),
            // Whole controllers, so endpoints added to them later stay hidden too.
            'hidden_tags' => $strings($layout['hidden_tags'] ?? []),
            'texts' => self::cleanTexts(is_array($layout['texts'] ?? null) ? $layout['texts'] : []),
            'meta' => self::cleanMeta(is_array($layout['meta'] ?? null) ? $layout['meta'] : []),
        ];
    }

    /**
     * Status (new / beta / deprecated with a sunset date and a replacement) and a note per operation.
     *
     * @param  array<string, mixed>  $meta
     * @return array{operations: array<string, array{status?: string, sunset?: string, replacement?: string, note?: string}>}
     */
    private static function cleanMeta(array $meta): array
    {
        $operations = [];
        foreach ((array) ($meta['operations'] ?? []) as $id => $values) {
            if (! is_string($id) || ! is_array($values)) {
                continue;
            }
            $status = in_array($values['status'] ?? null, self::STATUSES, true) ? $values['status'] : null;
            // A sunset / replacement belongs to a deprecation (set here or in code with ->deprecated()).
            $deprecated = $status === 'deprecated' || $status === null;
            $clean = array_filter([
                'status' => $status,
                'sunset' => $deprecated && is_string($values['sunset'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['sunset']) ? $values['sunset'] : null,
                'replacement' => $deprecated && is_string($values['replacement'] ?? null) && trim($values['replacement']) !== '' ? mb_substr(trim($values['replacement']), 0, 200) : null,
                'note' => is_string($values['note'] ?? null) && trim($values['note']) !== '' ? mb_substr(trim($values['note']), 0, 2000) : null,
            ], fn ($value) => $value !== null);
            if ($clean !== []) {
                $operations[$id] = $clean;
            }
        }

        return ['operations' => $operations];
    }

    /**
     * Only non-empty strings are kept, so the file stays small and an emptied field falls back to the code.
     *
     * @param  array<string, mixed>  $texts
     * @return array{info: array<string, array<string, string>>, tags: array<string, array<string, string>>, operations: array<string, array<string, array<string, string>>>}
     */
    private static function cleanTexts(array $texts): array
    {
        $text = fn ($value, int $max): ?string => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        $fields = function ($value) use ($text): array {
            $value = is_array($value) ? $value : [];

            return array_filter(['summary' => $text($value['summary'] ?? null, 200), 'title' => $text($value['title'] ?? null, 200), 'description' => $text($value['description'] ?? null, 5000)], fn ($v) => $v !== null);
        };

        $out = ['info' => [], 'tags' => [], 'operations' => []];
        foreach (self::LANGUAGES as $language) {
            $info = array_intersect_key($fields($texts['info'][$language] ?? null), ['title' => true, 'description' => true]);
            if ($info !== []) {
                $out['info'][$language] = $info;
            }
        }
        foreach ((array) ($texts['tags'] ?? []) as $tag => $languages) {
            foreach (self::LANGUAGES as $language) {
                $description = is_string($tag) ? $text($languages[$language] ?? null, 2000) : null;
                if ($description !== null) {
                    $out['tags'][$tag][$language] = $description;
                }
            }
        }
        foreach ((array) ($texts['operations'] ?? []) as $id => $languages) {
            foreach (self::LANGUAGES as $language) {
                $values = is_string($id) ? array_intersect_key($fields($languages[$language] ?? null), ['summary' => true, 'description' => true]) : [];
                if ($values !== []) {
                    $out['operations'][$id][$language] = $values;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $links
     * @return list<array{id: string, name: string, hash: string, tags: list<string>, expires: ?string, created: ?string}>
     */
    private static function cleanLinks(array $links): array
    {
        $date = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $out = [];
        foreach ($links as $link) {
            if (! is_array($link) || ! is_string($link['hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $link['hash'])) {
                continue;
            }
            $out[] = [
                'id' => is_string($link['id'] ?? null) ? mb_substr($link['id'], 0, 40) : substr($link['hash'], 0, 12),
                'name' => is_string($link['name'] ?? null) ? mb_substr(trim($link['name']), 0, 60) : 'Partner',
                'hash' => $link['hash'],
                // The token encrypted with the app key, so the dashboard can show the address again.
                'secret' => is_string($link['secret'] ?? null) ? $link['secret'] : null,
                'tags' => array_values(array_unique(array_filter((array) ($link['tags'] ?? []), 'is_string'))),
                // Single endpoints (operation ids), also ones hidden from the public docs.
                'operations' => array_values(array_unique(array_filter((array) ($link['operations'] ?? []), 'is_string'))),
                // Security schemes the link's docs show; null keeps every scheme of the docs.
                'security' => self::linkNames($link['security'] ?? null),
                // Versions it can open (null: every version) and shared environments it shows (null: all, []: none).
                'versions' => self::linkNames($link['versions'] ?? null),
                'environments' => self::linkNames($link['environments'] ?? null, true),
                'expires' => $date($link['expires'] ?? null),
                'created' => $date($link['created'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * A link's list of names; null when it takes the default (an empty list too, unless $allowEmpty).
     *
     * @return list<string>|null
     */
    private static function linkNames(mixed $names, bool $allowEmpty = false): ?array
    {
        if (! is_array($names)) {
            return null;
        }
        $names = array_values(array_unique(array_filter($names, fn ($name) => is_string($name) && $name !== '')));

        return $names === [] && ! $allowEmpty ? null : $names;
    }

    private static function merged(string $key, mixed $value, mixed $base): mixed
    {
        // Lists (several auto_login entries) replace the config value; maps are merged into it.
        if (in_array($key, self::MERGED, true) && is_array($value) && is_array($base) && ! array_is_list($value) && ! array_is_list($base)) {
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
        $json = self::encode($data);
        if ($json === null) {
            return false;
        }

        if (self::disk() !== null) {
            try {
                if (! Storage::disk(self::disk())->put(self::diskPath(), $json, ['visibility' => 'private'])) {
                    return false;
                }
            } catch (Throwable $e) {
                Debug::log('could not write the settings to the '.self::disk().' disk', $e);

                return false;
            }
            // Every server sees the save at once when the cache store is shared (redis, database…).
            self::cached(fn () => self::cache()->put(self::cacheKey(), $json, self::cacheSeconds()));

            return true;
        }

        return self::writeLocal($json);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function encode(array $data): ?string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }

    private static function writeLocal(string $json): bool
    {
        $path = self::localPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        // Atomic: write a temporary file, then rename over the old one.
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, $json) === false) {
            return false;
        }

        return rename($tmp, $path);
    }

    /**
     * The stored JSON, or null when nothing is saved. Read on every boot, so a disk is read through the cache
     * (`dashboard.settings_cache_ttl` seconds), and a disk that cannot be reached means "no settings", never an error.
     */
    private static function read(): ?string
    {
        if (self::disk() === null) {
            $file = is_file(self::localPath()) ? self::localPath() : (is_file(self::legacyPath()) ? self::legacyPath() : null);

            return $file !== null ? (string) file_get_contents($file) : null;
        }

        $load = function (): string {
            $disk = Storage::disk(self::disk());

            // "" for a missing file is cached too, so an empty disk is not asked again on every request.
            return $disk->exists(self::diskPath()) ? (string) $disk->get(self::diskPath()) : '';
        };

        try {
            $contents = self::cached(fn () => self::cache()->remember(self::cacheKey(), self::cacheSeconds(), $load), $load);
        } catch (Throwable $e) {
            Debug::log('could not read the settings from the '.self::disk().' disk', $e);

            return null;
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /** Why a disk failed, with the fix for the usual causes. */
    private static function diskFailure(string $disk, Throwable $e): string
    {
        $message = $e->getMessage();
        $driver = (string) config("filesystems.disks.{$disk}.driver");
        $adapters = ['s3' => 'league/flysystem-aws-s3-v3', 'ftp' => 'league/flysystem-ftp', 'sftp' => 'league/flysystem-sftp-v3'];
        if (isset($adapters[$driver]) && str_contains($message, 'League\\Flysystem') && str_contains($message, 'not found')) {
            return "The \"{$disk}\" disk needs the {$adapters[$driver]} package: composer require {$adapters[$driver]} \"^3.0\".";
        }

        return "The \"{$disk}\" disk failed: {$message}";
    }

    private static function delete(): bool
    {
        if (self::disk() === null) {
            foreach (array_unique([self::localPath(), self::legacyPath()]) as $path) {
                if (is_file($path) && ! unlink($path)) {
                    return false;
                }
            }

            return true;
        }

        try {
            $disk = Storage::disk(self::disk());
            if ($disk->exists(self::diskPath()) && ! $disk->delete(self::diskPath())) {
                return false;
            }
        } catch (Throwable $e) {
            Debug::log('could not remove the settings from the '.self::disk().' disk', $e);

            return false;
        }
        self::cached(fn () => self::cache()->forget(self::cacheKey()));

        return true;
    }

    /**
     * Runs a cache call; when the cache store fails, $fallback (or nothing) runs instead.
     */
    private static function cached(callable $call, ?callable $fallback = null): mixed
    {
        if (self::cacheSeconds() <= 0) {
            return $fallback !== null ? $fallback() : null;
        }
        try {
            return $call();
        } catch (Throwable $e) {
            Debug::log('the settings cache failed', $e);

            return $fallback !== null ? $fallback() : null;
        }
    }

    private static function cache(): CacheRepository
    {
        $store = config('swagger.dashboard.settings_cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private static function cacheKey(): string
    {
        return 'g4t-swagger:settings:'.md5(self::disk().':'.self::diskPath());
    }

    private static function cacheSeconds(): int
    {
        return (int) config('swagger.dashboard.settings_cache_ttl', 60);
    }
}
