<?php

namespace G4T\Swagger\Support;

/**
 * Location of the cached OpenAPI JSON.
 *
 * By default the cache lives in storage/app/swagger so it is only reachable through the
 * (optionally Basic-Auth protected) JSON endpoint. `cached_spec_location = public` restores the
 * legacy public/{cached_spec_path} location.
 */
final class CachedSpec
{
    public static function path(): string
    {
        return self::isPublic() ? self::legacyPublicPath() : storage_path('app/swagger/'.self::relativePath());
    }

    public static function legacyPublicPath(): string
    {
        return public_path(self::relativePath());
    }

    public static function isPublic(): bool
    {
        return config('swagger.cached_spec_location', 'storage') === 'public';
    }

    /**
     * Raw cached JSON, or null when missing/invalid. A hand-written legacy public file is still
     * honoured in `load_from_json` mode.
     */
    public static function read(): ?string
    {
        $candidates = [self::path()];
        if (config('swagger.load_from_json') && ! self::isPublic()) {
            $candidates[] = self::legacyPublicPath();
        }

        foreach ($candidates as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false || $raw === '') {
                continue;
            }
            json_decode($raw);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $raw;
            }
        }

        return null;
    }

    public static function write(string $json): bool
    {
        $path = self::path();
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            return false;
        }

        return file_put_contents($path, $json) !== false;
    }

    /**
     * @return list<string> removed files
     */
    public static function clear(): array
    {
        $removed = [];
        foreach (array_unique([self::path(), self::legacyPublicPath()]) as $path) {
            if (is_file($path) && unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }

    private static function relativePath(): string
    {
        return ltrim((string) config('swagger.cached_spec_path', 'doc.json'), '/');
    }
}
