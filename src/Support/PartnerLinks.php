<?php

namespace G4T\Swagger\Support;

use Illuminate\Http\Request;
use stdClass;

/**
 * Secret documentation links for partners / clients, created in the dashboard.
 *
 * A link opens the g4t UI without the docs password (and even with the docs turned off), showing only the
 * sections (tags) and single endpoints it allows. Only a sha256 of the token is stored, so the settings file never holds a link.
 */
final class PartnerLinks
{
    /** Query parameter (or X-Docs-Key header) carrying the token. */
    public const PARAM = 'key';

    private const ATTRIBUTE = 'g4t_swagger_partner_link';

    /**
     * The valid, not expired link of this request, with its token; null for everyone else.
     *
     * @return array{id: string, name: string, hash: string, tags: list<string>, operations: list<string>, expires: ?string, created: ?string, token: string}|null
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
     * The link's part of the document: every visible operation of its sections, plus the operations it picks
     * one by one, even hidden ones (from the dashboard or ->hiddenDoc()). A link with neither shows everything
     * visible. Tags without operations and schemas nothing reaches are dropped, so other sections do not leak.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $operations
     */
    public static function scope(stdClass $spec, array $tags, array $operations = []): stdClass
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
