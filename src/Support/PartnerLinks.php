<?php

namespace G4T\Swagger\Support;

use Illuminate\Http\Request;
use stdClass;

/**
 * Secret documentation links for partners / clients, created in the dashboard.
 *
 * A link opens the g4t UI without the docs password (and even with the docs turned off), showing only the
 * sections (tags) it allows. Only a sha256 of the token is stored, so the settings file never holds a link.
 */
final class PartnerLinks
{
    /** Query parameter (or X-Docs-Key header) carrying the token. */
    public const PARAM = 'key';

    private const ATTRIBUTE = 'g4t_swagger_partner_link';

    /**
     * The valid, not expired link of this request, with its token; null for everyone else.
     *
     * @return array{id: string, name: string, hash: string, tags: list<string>, expires: ?string, created: ?string, token: string}|null
     */
    public static function current(Request $request): ?array
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $token = $request->query(self::PARAM) ?? $request->header('X-Docs-Key');
        $link = null;
        if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{24,128}$/', $token)) {
            $hash = hash('sha256', $token);
            foreach (Settings::links() as $candidate) {
                if (hash_equals($candidate['hash'], $hash) && ! self::expired($candidate)) {
                    $link = $candidate + ['token' => $token];

                    break;
                }
            }
        }
        $request->attributes->set(self::ATTRIBUTE, $link);

        return $link;
    }

    /**
     * @param  array{expires?: ?string}  $link
     */
    public static function expired(array $link): bool
    {
        return ! empty($link['expires']) && $link['expires'] < now()->toDateString();
    }

    /**
     * Only the operations of the allowed tags, those tags, and the schemas they still reference.
     */
    public static function scope(stdClass $spec, array $tags): stdClass
    {
        if ($tags === []) {
            return $spec;
        }
        $allowed = array_flip($tags);

        $paths = new stdClass;
        foreach (get_object_vars($spec->paths ?? new stdClass) as $uri => $operations) {
            $kept = new stdClass;
            foreach (get_object_vars($operations) as $method => $operation) {
                if (is_object($operation) && isset($allowed[$operation->tags[0] ?? ''])) {
                    $kept->{$method} = $operation;
                }
            }
            if (get_object_vars($kept) !== []) {
                $paths->{$uri} = $kept;
            }
        }
        $spec->paths = $paths;
        $spec->tags = array_values(array_filter(is_array($spec->tags ?? null) ? $spec->tags : [], fn ($tag) => is_object($tag) && isset($allowed[$tag->name ?? ''])));

        // Drop schemas the remaining operations cannot reach (no names of other sections leak).
        $schemas = $spec->components->schemas ?? null;
        if ($schemas instanceof stdClass) {
            $reachable = [];
            $pending = self::refs(json_encode($paths));
            while ($pending !== []) {
                $name = array_pop($pending);
                if (isset($reachable[$name]) || ! isset($schemas->{$name})) {
                    continue;
                }
                $reachable[$name] = true;
                $pending = array_merge($pending, self::refs(json_encode($schemas->{$name})));
            }
            $spec->components->schemas = (object) array_intersect_key(get_object_vars($schemas), $reachable);
        }

        return $spec;
    }

    /**
     * @return list<string>
     */
    private static function refs(string $json): array
    {
        preg_match_all('~#\\\\?/components\\\\?/schemas\\\\?/([^"\\\\]+)~', $json, $matches);

        return array_values(array_unique($matches[1]));
    }
}
