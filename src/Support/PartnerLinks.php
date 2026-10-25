<?php

namespace G4T\Swagger\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use stdClass;

/**
 * Secret documentation links for partners / clients, created in the dashboard.
 *
 * A link opens the g4t UI without the docs password (and even with the docs turned off), showing only the
 * sections (tags) and single endpoints it allows. Requests are matched on a sha256 of the token; the token itself is
 * kept encrypted with the app key, only so the dashboard can show the address again.
 */
final class PartnerLinks
{
    /** Query parameter (or X-Docs-Key header) carrying the token. */
    public const PARAM = 'key';

    private const ATTRIBUTE = 'g4t_swagger_partner_link';

    /**
     * The valid, not expired link of this request, with its token; null for everyone else.
     *
     * @return array{id: string, name: string, hash: string, tags: list<string>, operations: list<string>, security: ?list<string>, versions: ?list<string>, environments: ?list<string>, expires: ?string, created: ?string, token: string}|null
     */
    public static function current(Request $request): ?array
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $token = self::tokenOf($request);
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
     * The link's token, decrypted for the dashboard; null for links saved before the address was kept,
     * or when the app key changed (the hash still opens the docs, so only the address is lost).
     *
     * @param  array{hash: string, secret?: ?string}  $link
     */
    public static function token(array $link): ?string
    {
        if (empty($link['secret'])) {
            return null;
        }
        try {
            $token = Crypt::decryptString($link['secret']);
        } catch (DecryptException) {
            return null;
        }

        return hash_equals($link['hash'], hash('sha256', $token)) ? $token : null;
    }

    /**
     * Why the link of this request does not open the docs: "expired" (with its date) or "invalid" (unknown,
     * replaced, removed or cut short). Null when the request carries no link, or a working one.
     *
     * @return array{reason: string, expires: ?string}|null
     */
    public static function problem(Request $request): ?array
    {
        $token = self::tokenOf($request);
        if ($token === null || self::current($request) !== null) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_-]{24,128}$/', $token)) {
            $hash = hash('sha256', $token);
            foreach (Settings::links() as $link) {
                if (hash_equals($link['hash'], $hash)) {
                    return ['reason' => 'expired', 'expires' => $link['expires']];
                }
            }
        }

        return ['reason' => 'invalid', 'expires' => null];
    }

    /**
     * @param  array{expires?: ?string}  $link
     */
    public static function expired(array $link): bool
    {
        return ! empty($link['expires']) && $link['expires'] < now()->toDateString();
    }

    /** The token the request carries (?key=… or the X-Docs-Key header); null when it carries none. */
    private static function tokenOf(Request $request): ?string
    {
        $token = $request->query(self::PARAM) ?? $request->header('X-Docs-Key');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The security schemes a link's docs keep, among the ones that still exist; null keeps every scheme
     * (the link takes the default, or none of its schemes is left).
     *
     * @param  array{security?: ?list<string>}  $link
     * @return list<string>|null
     */
    public static function schemes(array $link): ?array
    {
        if (empty($link['security'])) {
            return null;
        }
        $existing = array_map('strval', array_keys((array) config('swagger.security_schemes', [])));
        $kept = array_values(array_intersect($link['security'], $existing));

        return $kept === [] ? null : $kept;
    }

    /**
     * The versions a link opens, among the configured ones; null opens every version (the link takes
     * the default, or none of its versions is left).
     *
     * @param  array{versions?: ?list<string>}  $link
     * @return list<string>|null
     */
    public static function versions(array $link): ?array
    {
        if (empty($link['versions'])) {
            return null;
        }
        $kept = array_values(array_intersect(array_map('strval', (array) config('swagger.versions', [])), $link['versions']));

        return $kept === [] ? null : $kept;
    }

    /**
     * Names of the shared environments a link shows: null for all of them, [] for none.
     *
     * @param  array{environments?: ?list<string>}  $link
     * @return list<string>|null
     */
    public static function environments(array $link): ?array
    {
        return isset($link['environments']) && is_array($link['environments']) ? $link['environments'] : null;
    }

    /**
     * The link's part of the document: every visible operation of its sections, plus the operations it picks
     * one by one, even hidden ones (from the dashboard or ->hiddenDoc()). A link with neither shows everything
     * visible. Tags without operations and schemas nothing reaches are dropped, so other sections do not leak.
     *
     * With $schemes, protected operations ask for those schemes only, and the others leave the document.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $operations
     * @param  list<string>|null  $schemes
     */
    public static function scope(stdClass $spec, array $tags, array $operations = [], ?array $schemes = null): stdClass
    {
        $allowedTags = array_flip($tags);
        $picked = array_flip($operations);
        $layout = Settings::layout();
        $hidden = array_flip($layout['hidden']);
        $hiddenTags = array_flip($layout['hidden_tags']);
        $everything = $tags === [] && $operations === [];

        $paths = new stdClass;
        $usedTags = [];
        foreach (get_object_vars($spec->paths ?? new stdClass) as $uri => $methods) {
            $kept = new stdClass;
            foreach (get_object_vars($methods) as $method => $operation) {
                if (! is_object($operation)) {
                    continue;
                }
                $id = (string) ($operation->operationId ?? '');
                $tag = (string) ($operation->tags[0] ?? '');
                $isHidden = ! empty($operation->{'x-hidden-in-code'}) || isset($hidden[$id]) || isset($hiddenTags[$tag]);
                if (isset($picked[$id]) || (! $isHidden && ($everything || isset($allowedTags[$tag])))) {
                    unset($operation->{'x-hidden-in-code'});
                    if ($schemes !== null && isset($operation->security)) {
                        $operation->security = self::requirements($operation->security, $schemes);
                    }
                    $kept->{$method} = $operation;
                    $usedTags[$tag] = true;
                }
            }
            if (get_object_vars($kept) !== []) {
                $paths->{$uri} = $kept;
            }
        }
        $spec->paths = $paths;
        $spec->tags = array_values(array_filter(is_array($spec->tags ?? null) ? $spec->tags : [], fn ($tag) => is_object($tag) && isset($usedTags[$tag->name ?? ''])));

        if ($schemes !== null) {
            if (isset($spec->security)) {
                $spec->security = self::requirements($spec->security, $schemes);
            }
            $defined = $spec->components->securitySchemes ?? null;
            if ($defined instanceof stdClass) {
                $spec->components->securitySchemes = (object) array_intersect_key(get_object_vars($defined), array_flip($schemes));
            }
        }

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
     * A protected requirement list becomes "any one of the link's schemes"; an open one ([] or {}) stays open.
     *
     * @param  list<string>  $schemes
     * @return list<stdClass>|mixed
     */
    private static function requirements(mixed $security, array $schemes): mixed
    {
        $protected = is_array($security) && array_filter($security, fn ($requirement) => (array) $requirement !== []) !== [];
        if (! $protected) {
            return $security;
        }

        return array_map(fn (string $scheme) => (object) [$scheme => []], $schemes);
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
