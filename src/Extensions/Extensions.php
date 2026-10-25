<?php

namespace G4T\Swagger\Extensions;

use G4T\Swagger\Support\Debug;
use G4T\Swagger\Swagger;
use Throwable;

/**
 * The registered extensions and the calls into them. With none registered, every call is a no-op.
 *
 * @internal Use {@see Swagger::extend()} or config('swagger.extensions').
 */
final class Extensions
{
    /** @var list<Extension|class-string<Extension>> registered from code */
    private static array $registered = [];

    /** @var list<Extension>|null */
    private static ?array $resolved = null;

    /**
     * @param  Extension|class-string<Extension>  $extension
     */
    public static function add(Extension|string $extension): void
    {
        self::$registered[] = $extension;
        self::$resolved = null;
    }

    /** Forgets the extensions registered from code (tests). */
    public static function flush(): void
    {
        self::$registered = [];
        self::$resolved = null;
    }

    /**
     * Extensions from the config file first, then the ones registered from code; each class once.
     *
     * @return list<Extension>
     */
    public static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $out = [];
        $seen = [];
        foreach (array_merge((array) config('swagger.extensions', []), self::$registered) as $extension) {
            try {
                $instance = is_string($extension) ? app()->make($extension) : $extension;
            } catch (Throwable $e) {
                Debug::log('could not create extension '.(is_string($extension) ? $extension : get_class($extension)), $e);

                continue;
            }
            if (! $instance instanceof Extension || isset($seen[get_class($instance)])) {
                continue;
            }
            $seen[get_class($instance)] = true;
            $out[] = $instance;
        }

        return self::$resolved = $out;
    }

    public static function any(): bool
    {
        return self::all() !== [];
    }

    public static function request(RouteInfo $route): ?RequestSpec
    {
        foreach (self::all() as $extension) {
            $spec = self::call($extension, 'request', fn () => $extension->request($route));
            if ($spec instanceof RequestSpec) {
                return $spec;
            }
        }

        return null;
    }

    public static function response(ReturnedValue $value): ?Example
    {
        foreach (self::all() as $extension) {
            $example = self::call($extension, 'response', fn () => $extension->response($value));
            if ($example instanceof Example) {
                return $example;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public static function operation(array $operation, RouteInfo $route): array
    {
        foreach (self::all() as $extension) {
            $changed = self::call($extension, 'operation', fn () => $extension->operation($operation, $route));
            if (is_array($changed)) {
                $operation = $changed;
            }
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function document(array $document): array
    {
        foreach (self::all() as $extension) {
            $changed = self::call($extension, 'document', fn () => $extension->document($document));
            if (is_array($changed)) {
                $document = $changed;
            }
        }

        return $document;
    }

    private static function call(Extension $extension, string $hook, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Debug::log(get_class($extension)."::{$hook}() failed", $e);

            return null;
        }
    }
}
