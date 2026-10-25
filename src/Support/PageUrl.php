<?php

namespace G4T\Swagger\Support;

/**
 * URLs the documentation pages fetch, without scheme and host: the browser resolves them against the page,
 * so they keep working when the app forces https (URL::forceScheme), runs behind a proxy, or is opened
 * through another host / port than APP_URL. A sub-directory install keeps its base path.
 */
final class PageUrl
{
    public static function to(string $path): string
    {
        return self::relative(url($path));
    }

    public static function asset(string $path): string
    {
        return self::relative(asset($path));
    }

    public static function relative(string $url): string
    {
        $relative = preg_replace('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', '', $url);

        return $relative === '' ? '/' : (string) $relative;
    }
}
