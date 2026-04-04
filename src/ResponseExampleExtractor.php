<?php

namespace G4T\Swagger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable as FillableAttribute;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Derives OpenAPI examples from controller returns: response()->json([...]), plain arrays/strings,
 * and JsonResource::collection / new Resource patterns.
 */
class ResponseExampleExtractor
{
    public function extract(string $controllerAction): array|string|null
    {
        if ($controllerAction === 'Closure' || ! str_contains($controllerAction, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $controllerAction, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        try {
            $ref = new ReflectionMethod($class, $method);
            $file = $ref->getFileName();
            if (! $file || ! is_readable($file)) {
                return null;
            }

            $lines = file($file);
            if ($lines === false) {
                return null;
            }

            $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
            $body = $this->stripFullLineComments($body);

            $fromJson = $this->extractFromResponseJson($body);
            if ($fromJson !== null) {
                return $fromJson;
            }

            $eagerLoadPaths = $this->extractEagerLoadPathsFromControllerBody($body);

            $keyedResources = $this->extractKeyedArrayWithNewResource($body, $file, $eagerLoadPaths);
            if ($keyedResources !== null) {
                return $keyedResources;
            }

            $keyedModels = $this->extractKeyedArrayWithModelVariable($body, $file);
            if ($keyedModels !== null) {
                return $keyedModels;
            }

            $plain = $this->extractFromPlainReturn($body);
            if ($plain !== null) {
                return $plain;
            }

            return $this->extractFromResourceReturn($body, $file, $eagerLoadPaths);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extracts error / non-success JSON examples from the controller: response()->json([...], 4xx/5xx) and abort(4xx, '...').
     *
     * @return array<int, array<string, mixed>> status code => example payload
     */
    public function extractErrorResponseExamples(string $controllerAction): array
    {
        if ($controllerAction === 'Closure' || ! str_contains($controllerAction, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $controllerAction, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        try {
            $ref = new ReflectionMethod($class, $method);
            $file = $ref->getFileName();
            if (! $file || ! is_readable($file)) {
                return [];
            }

            $lines = file($file);
            if ($lines === false) {
                return [];
            }

            $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
            $body = $this->stripFullLineComments($body);

            $fromJson = $this->extractResponseJsonErrorPayloadsFromBody($body);
            $fromAbort = $this->extractAbortExamplesFromBody($body);

            return $fromJson + $fromAbort;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Drops lines that are only // comments so commented-out returns are not parsed as real code.
     */
    private function stripFullLineComments(string $body): string
    {
        $lines = explode("\n", $body);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\/\//', $line)) {
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractResponseJsonErrorPayloadsFromBody(string $body): array
    {
        $out = [];
        $offset = 0;
        while (($pos = stripos($body, 'response()->json', $offset)) !== false) {
            $openParen = strpos($body, '(', $pos);
            if ($openParen === false) {
                break;
            }
            $bracketPos = strpos($body, '[', $openParen);
            if ($bracketPos === false) {
                $offset = $pos + 1;

                continue;
            }
            $inner = $this->extractBalancedFrom($body, $bracketPos, '[', ']');
            if ($inner === null) {
                $offset = $pos + 1;

                continue;
            }
            $afterClose = $bracketPos + 1 + strlen($inner) + 1;
            $rest = substr($body, $afterClose);
            if (! preg_match('/^\s*,\s*(\d{3})\s*\)/', $rest, $m)) {
                $offset = $afterClose;

                continue;
            }
            $status = (int) $m[1];
            if ($status < 400) {
                $offset = $afterClose;

                continue;
            }
            $code = '[' . $inner . ']';
            if (preg_match('/\$|->/', $code)) {
                $offset = $afterClose;

                continue;
            }
            try {
                $result = eval('return ' . $code . ';');
                if (is_array($result)) {
                    $out[$status] = $result;
                }
            } catch (Throwable) {
            }
            $offset = $afterClose;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractAbortExamplesFromBody(string $body): array
    {
        $out = [];
        if (preg_match_all(
            '/\babort\s*\(\s*(\d{3})\s*(?:,\s*([\'"])(.+?)\2\s*)?\)/s',
            $body,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $status = (int) $m[1];
                if ($status < 400) {
                    continue;
                }
                $msg = isset($m[3]) && $m[3] !== '' ? $m[3] : 'An error occurred.';
                $out[$status] = ['message' => $msg];
            }
        }

        return $out;
    }

    /**
     * return ['user' => new UserResource($user), 'users' => UserResource::collection($users), ...].
     * Single resources: flat per key (no "data" wrapper). Collections: wrapped like Resource::collection (data / pagination).
     *
     * @return array<string, mixed>|null
     */
    private function extractKeyedArrayWithNewResource(string $body, string $controllerFile, array $eagerLoadPaths): ?array
    {
        $offset = 0;
        while (preg_match('/\breturn\s+/s', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $afterReturn = $m[0][1] + strlen($m[0][0]);
            $pos = $this->skipWhitespace($body, $afterReturn);
            if ($pos >= strlen($body) || $body[$pos] !== '[') {
                $offset = $m[0][1] + 1;

                continue;
            }
            $inner = $this->extractBalancedFrom($body, $pos, '[', ']');
            if ($inner === null) {
                $offset = $m[0][1] + 1;

                continue;
            }
            if (! $this->innerLooksLikeKeyedResourceReturn($inner)) {
                $offset = $m[0][1] + 1;

                continue;
            }
            $result = [];
            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*new\s+([\w\\\\]+)\s*\(/s",
                $inner,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $fqcn = $this->resolveShortClassInPhpFile($match[2], $controllerFile);
                    if ($fqcn === null || ! is_subclass_of($fqcn, JsonResource::class)) {
                        continue;
                    }
                    $nested = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
                    if ($nested !== null) {
                        $result[$match[1]] = $nested;
                    }
                }
            }
            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*([\w\\\\]+)::collection\s*\(/s",
                $inner,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $nested = $this->exampleFromResourceClassCollection($match[2], $controllerFile, $eagerLoadPaths, $body);
                    if ($nested !== null) {
                        $result[$match[1]] = $nested;
                    }
                }
            }
            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*([\w\\\\]+)::make\s*\(/s",
                $inner,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $fqcn = $this->resolveShortClassInPhpFile($match[2], $controllerFile);
                    if ($fqcn === null || ! is_subclass_of($fqcn, JsonResource::class)) {
                        continue;
                    }
                    $nested = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
                    if ($nested !== null) {
                        $result[$match[1]] = $this->wrapSingleResourceUnwrapped($nested);
                    }
                }
            }
            if ($result !== []) {
                return $result;
            }
            $offset = $m[0][1] + 1;
        }

        return null;
    }

    /**
     * Keyed return array must reference a JsonResource (new, ::collection, or ::make).
     */
    private function innerLooksLikeKeyedResourceReturn(string $inner): bool
    {
        return (bool) preg_match('/\bnew\s+[\w\\\\]+\s*\(/', $inner)
            || (bool) preg_match('/[\w\\\\]+::(?:collection|make)\s*\(/', $inner);
    }

    /**
     * Same shape as return SomeResource::collection(...) for OpenAPI examples.
     *
     * @return array<string, mixed>|null
     */
    private function exampleFromResourceClassCollection(string $shortClass, string $controllerFile, array $eagerLoadPaths, string $body): ?array
    {
        $fqcn = $this->resolveShortClassInPhpFile($shortClass, $controllerFile);
        if ($fqcn === null || ! is_subclass_of($fqcn, JsonResource::class)) {
            return null;
        }

        $example = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
        if ($example === null) {
            return null;
        }

        $paginationType = $this->detectPaginationType($body);
        if ($paginationType !== 'none') {
            return $this->buildPaginatedCollectionExample($example, $paginationType);
        }

        return $this->wrapNonPaginatedCollection($example);
    }

    /**
     * return ['user' => $user] — infer App\\Models\\User from $user and build a row-shaped example.
     *
     * @return array<string, mixed>|null
     */
    private function extractKeyedArrayWithModelVariable(string $body, string $controllerFile): ?array
    {
        $offset = 0;
        while (preg_match('/\breturn\s+/s', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $afterReturn = $m[0][1] + strlen($m[0][0]);
            $pos = $this->skipWhitespace($body, $afterReturn);
            if ($pos >= strlen($body) || $body[$pos] !== '[') {
                $offset = $m[0][1] + 1;

                continue;
            }
            $inner = $this->extractBalancedFrom($body, $pos, '[', ']');
            if ($inner === null) {
                $offset = $m[0][1] + 1;

                continue;
            }
            if (preg_match('/=>\s*new\s+/s', $inner)) {
                $offset = $m[0][1] + 1;

                continue;
            }
            $result = [];
            // Inner omits the closing `]`, so the last pair ends at `$var` with no trailing `,` or `]`.
            // Single-quoted pattern so `\b` is a regex word boundary (in double-quoted PHP, `\b` is backspace).
            if (preg_match_all(
                '#[\'"](\w+)[\'"]\s*=>\s*\$(\w+)\b#',
                $inner,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $varName = $match[2];
                    $modelClass = $this->resolveModelClassFromVariableName($varName, $controllerFile);
                    if ($modelClass === null) {
                        continue;
                    }
                    $row = $this->exampleFromEloquentModel($modelClass);
                    if ($row !== null) {
                        $result[$match[1]] = $row;
                    }
                }
            }
            if ($result !== []) {
                return $result;
            }
            $offset = $m[0][1] + 1;
        }

        return null;
    }

    private function resolveModelClassFromVariableName(string $varName, string $controllerFile): ?string
    {
        $candidates = array_unique([
            ucfirst($varName),
            strtolower($varName),
            $varName,
        ]);

        foreach ($candidates as $name) {
            $fqcn = $this->resolveShortClassInPhpFile($name, $controllerFile);
            if ($fqcn !== null && class_exists($fqcn) && is_subclass_of($fqcn, Model::class)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exampleFromEloquentModel(string $modelClass): ?array
    {
        try {
            $ref = new ReflectionClass($modelClass);
            if (! $ref->isSubclassOf(Model::class)) {
                return null;
            }

            $fillable = null;
            foreach ($ref->getAttributes(FillableAttribute::class) as $attr) {
                $fillable = $attr->newInstance()->columns;
                break;
            }

            if ($fillable === null) {
                $defaults = $ref->getDefaultProperties();
                if (isset($defaults['fillable']) && is_array($defaults['fillable'])) {
                    $fillable = $defaults['fillable'];
                }
            }

            $out = ['id' => 1];

            if (is_array($fillable) && $fillable !== []) {
                foreach ($fillable as $field) {
                    if ($field === 'password') {
                        $out[$field] = 'hashed';

                        continue;
                    }
                    $out[$field] = $this->guessExampleForProperty((string) $field);
                }
            }

            $out['created_at'] = '2024-01-01T12:00:00.000000Z';
            $out['updated_at'] = '2024-01-01T12:00:00.000000Z';

            return $out;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Handles return ["message" => "..."], return ["..."], return "..." / return '...'.
     * Scans every return in source order until one yields a literal array or string.
     */
    private function extractFromPlainReturn(string $body): array|string|null
    {
        $offset = 0;
        while (preg_match('/\breturn\s+/s', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $afterReturn = $m[0][1] + strlen($m[0][0]);
            $pos = $this->skipWhitespace($body, $afterReturn);
            if ($pos >= strlen($body)) {
                $offset = $m[0][1] + 1;

                continue;
            }

            if ($body[$pos] === '[') {
                $inner = $this->extractBalancedFrom($body, $pos, '[', ']');
                if ($inner !== null) {
                    $code = '[' . $inner . ']';
                    if (! preg_match('/\$|->/', $code)) {
                        try {
                            $result = eval('return ' . $code . ';');
                            if (is_array($result)) {
                                return $result;
                            }
                        } catch (Throwable) {
                        }
                    }
                }
            } elseif ($body[$pos] === '"' || $body[$pos] === "'") {
                $expr = $this->extractStringLiteralExpression($body, $pos);
                if ($expr !== null) {
                    if ($expr[0] === '"' && preg_match('/(?<!\\\\)\$(?=\w)/', $expr)) {
                        $offset = $m[0][1] + 1;

                        continue;
                    }
                    try {
                        $result = eval('return ' . $expr . ';');
                        if (is_string($result)) {
                            return $result;
                        }
                    } catch (Throwable) {
                    }
                }
            }

            $offset = $m[0][1] + 1;
        }

        return null;
    }

    private function skipWhitespace(string $s, int $start): int
    {
        $len = strlen($s);
        while ($start < $len && ctype_space($s[$start])) {
            $start++;
        }

        return $start;
    }

    /**
     * Returns the full PHP string literal including quotes (single or double).
     */
    private function extractStringLiteralExpression(string $s, int $start): ?string
    {
        $len = strlen($s);
        if ($start >= $len) {
            return null;
        }
        $q = $s[$start];
        if ($q !== '"' && $q !== "'") {
            return null;
        }
        $i = $start + 1;
        while ($i < $len) {
            if ($s[$i] === '\\') {
                $i += 2;

                continue;
            }
            if ($s[$i] === $q) {
                return substr($s, $start, $i - $start + 1);
            }
            $i++;
        }

        return null;
    }

    /**
     * Success examples: use response()->json([...]) only when it is not an error status (4xx/5xx).
     * Otherwise the first branch (e.g. 404) would hide the real 200 return (array/resource).
     */
    private function extractFromResponseJson(string $body): ?array
    {
        $offset = 0;
        while (($pos = stripos($body, 'response()->json', $offset)) !== false) {
            $openParen = strpos($body, '(', $pos);
            if ($openParen === false) {
                break;
            }

            $firstArgStart = $this->skipWhitespace($body, $openParen + 1);
            if ($firstArgStart >= strlen($body) || $body[$firstArgStart] !== '[') {
                $offset = $pos + 1;

                continue;
            }

            $bracketPos = $firstArgStart;
            $closeBracketIdx = $this->findBalancedCloseIndex($body, $bracketPos, '[', ']');
            if ($closeBracketIdx === null) {
                $offset = $pos + 1;

                continue;
            }

            $afterBracket = $this->skipWhitespace($body, $closeBracketIdx + 1);
            $rest = substr($body, $afterBracket);
            if (preg_match('/^\s*,\s*(4\d\d|5\d\d)\s*\)/', $rest)) {
                $offset = $pos + 1;

                continue;
            }

            $inner = $this->extractBalancedFrom($body, $bracketPos, '[', ']');
            if ($inner === null) {
                $offset = $pos + 1;

                continue;
            }

            $code = '[' . $inner . ']';
            if (preg_match('/\$|->/', $code)) {
                $offset = $pos + 1;

                continue;
            }

            try {
                $result = eval('return ' . $code . ';');
                if (is_array($result)) {
                    return $result;
                }
            } catch (Throwable) {
            }

            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * Index of the closing bracket that pairs with $open at $start (same rules as extractBalancedFrom).
     */
    private function findBalancedCloseIndex(string $s, int $start, string $open, string $close): ?int
    {
        $len = strlen($s);
        if ($start >= $len || $s[$start] !== $open) {
            return null;
        }

        $depth = 0;
        $i = $start;
        while ($i < $len) {
            $c = $s[$i];

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $i++;
                while ($i < $len) {
                    if ($s[$i] === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($s[$i] === $quote) {
                        $i++;

                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            $i++;
        }

        return null;
    }

    private function extractBalancedFrom(string $s, int $start, string $open, string $close): ?string
    {
        $len = strlen($s);
        if ($start >= $len || $s[$start] !== $open) {
            return null;
        }

        $depth = 0;
        $i = $start;
        while ($i < $len) {
            $c = $s[$i];

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $i++;
                while ($i < $len) {
                    if ($s[$i] === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($s[$i] === $quote) {
                        $i++;

                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start + 1, $i - $start - 1);
                }
            }
            $i++;
        }

        return null;
    }

    /**
     * Collects relation paths from Eloquent eager loads in the controller method (e.g. with('users.governorate')).
     *
     * @return list<string> Dot paths such as "users", "users.governorate"
     */
    private function extractEagerLoadPathsFromControllerBody(string $body): array
    {
        $paths = [];

        if (preg_match_all('/(?:->|::)with\s*\(\s*([\'"])(.+?)\1/s', $body, $m)) {
            foreach ($m[2] as $p) {
                $paths[] = $p;
            }
        }

        if (preg_match_all('/(?:->|::)with\s*\(\s*\[([\s\S]*?)\]\s*\)/s', $body, $blocks)) {
            foreach ($blocks[1] as $inner) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $inner, $items)) {
                    foreach ($items[1] as $p) {
                        $paths[] = $p;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Whether a relation path (e.g. "users" or "users.governorate") is covered by the controller's with() list.
     */
    private function isRelationIncludedInEagerLoad(string $path, array $eagerLoadPaths): bool
    {
        foreach ($eagerLoadPaths as $e) {
            if ($e === $path) {
                return true;
            }
            if (str_starts_with($e, $path . '.')) {
                return true;
            }
        }

        return false;
    }

    private function buildRelationPath(string $relationPrefix, string $relationName): string
    {
        return $relationPrefix === '' ? $relationName : $relationPrefix . '.' . $relationName;
    }

    /**
     * @param  bool  $allowWithoutEagerLoad  When true (e.g. Resource::collection('relation') string), show nested example if controller has no with().
     */
    private function shouldIncludeNestedRelation(string $fullPath, array $eagerLoadPaths, bool $allowWithoutEagerLoad): bool
    {
        if ($eagerLoadPaths === []) {
            return $allowWithoutEagerLoad;
        }

        return $this->isRelationIncludedInEagerLoad($fullPath, $eagerLoadPaths);
    }

    private function extractFromResourceReturn(string $body, string $controllerFile, array $eagerLoadPaths = []): ?array
    {
        $className = null;
        $isCollection = false;

        if (preg_match('/return\s+([\w\\\\]+)::(?:collection|make)\s*\(/', $body, $m)) {
            $className = $m[1];
            $isCollection = str_contains($m[0], '::collection');
        } elseif (preg_match('/return\s+new\s+([\w\\\\]+)\s*\(/', $body, $m)) {
            $className = $m[1];
        }

        if ($className === null) {
            return null;
        }

        $fqcn = $this->resolveShortClassInPhpFile($className, $controllerFile);
        if ($fqcn === null || ! class_exists($fqcn)) {
            return null;
        }

        if (! is_subclass_of($fqcn, JsonResource::class)) {
            return null;
        }

        $example = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
        if ($example === null) {
            return null;
        }

        if ($isCollection) {
            $paginationType = $this->detectPaginationType($body);

            if ($paginationType !== 'none') {
                return $this->buildPaginatedCollectionExample($example, $paginationType);
            }

            return $this->wrapNonPaginatedCollection($example);
        }

        return $this->wrapSingleResourceUnwrapped($example);
    }

    /**
     * Collection responses use AnonymousResourceCollection::$wrap (inherits JsonResource::$wrap),
     * not the collecting resource class static (e.g. UserResource::$wrap).
     */
    private function getCollectionWrap(): ?string
    {
        try {
            return JsonResource::$wrap;
        } catch (Throwable) {
            return 'data';
        }
    }

    /**
     * Single JsonResource return: OpenAPI example matches the resource payload (no outer "data" wrapper).
     */
    private function wrapSingleResourceUnwrapped(array $example): array
    {
        return $example;
    }

    /**
     * Non-paginated Resource::collection(): root is either { "data": [...] } or a JSON array [...].
     * When config swagger.unwrap_resource_collection_examples is true, examples use a top-level array
     * [{ ... }] (no "data" key), matching JsonResource::withoutWrapping() / $wrap = null.
     */
    private function wrapNonPaginatedCollection(array $itemExample): array
    {
        if (config('swagger.unwrap_resource_collection_examples', false)) {
            return [$itemExample];
        }

        $wrap = $this->getCollectionWrap();

        if ($wrap !== null && $wrap !== '') {
            return [$wrap => [$itemExample]];
        }

        return [$itemExample];
    }

    private function detectPaginationType(string $body): string
    {
        if (preg_match('/\bcursorPaginate\s*\(/', $body)) {
            return 'cursor';
        }
        if (preg_match('/\b(?:paginate|simplePaginate)\s*\(/', $body)) {
            return 'length';
        }

        return 'none';
    }

    /**
     * Paginated Resource::collection() matches Laravel: data (or custom wrap key), links, meta.
     */
    private function buildPaginatedCollectionExample(array $itemExample, string $paginationType): array
    {
        $wrap = $this->getCollectionWrap();
        $dataKey = ($wrap !== null && $wrap !== '') ? $wrap : 'data';

        $links = [
            'first' => 'http://example.com/api?page=1',
            'last' => 'http://example.com/api?page=1',
            'prev' => null,
            'next' => null,
        ];

        if ($paginationType === 'cursor') {
            $meta = [
                'path' => 'http://example.com/api',
                'per_page' => 15,
                'next_cursor' => null,
                'prev_cursor' => null,
            ];
        } else {
            $meta = [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 1,
                'links' => [],
                'path' => 'http://example.com/api',
                'per_page' => 15,
                'to' => 1,
                'total' => 1,
            ];
        }

        return [
            $dataKey => [$itemExample],
            'links' => $links,
            'meta' => $meta,
        ];
    }

    /**
     * Resolve a short class name to FQCN using the PHP file's namespace and use imports.
     */
    private function resolveShortClassInPhpFile(string $shortName, string $phpFilePath): ?string
    {
        if (str_contains($shortName, '\\') && class_exists($shortName)) {
            return $shortName;
        }

        $content = @file_get_contents($phpFilePath);
        if ($content === false) {
            return null;
        }

        $escaped = preg_quote($shortName, '/');
        if (preg_match('/^use\s+([\w\\\\]+\\\\' . $escaped . ')\s*;/m', $content, $m)) {
            return $m[1];
        }

        if (preg_match_all('/^use\s+([\w\\\\]+)\s*;/m', $content, $useMatches)) {
            foreach ($useMatches[1] as $full) {
                $pos = strrpos($full, '\\');
                $leaf = $pos === false ? $full : substr($full, $pos + 1);
                if ($leaf === $shortName && class_exists($full)) {
                    return $full;
                }
            }
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $content, $ns)) {
            $candidate = trim($ns[1]) . '\\' . $shortName;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $visited  Prevents infinite recursion on circular resource graphs (key: class@relationPrefix).
     * @param  list<string>          $eagerLoadPaths  Relation paths from controller with(); empty = omit nested relations in examples.
     */
    private function exampleFromJsonResourceToArray(string $resourceClass, array $visited = [], array $eagerLoadPaths = [], string $relationPrefix = ''): ?array
    {
        $visitKey = $resourceClass . '@' . $relationPrefix;
        if (isset($visited[$visitKey])) {
            return null;
        }

        $visited[$visitKey] = true;

        try {
            $ref = new ReflectionClass($resourceClass);
            if (! $ref->hasMethod('toArray')) {
                return null;
            }

            $method = $ref->getMethod('toArray');
            $file = $method->getFileName();
            if (! $file || ! is_readable($file)) {
                return null;
            }

            $lines = file($file);
            if ($lines === false) {
                return null;
            }

            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            $returnPos = strpos($body, 'return');
            if ($returnPos === false) {
                return null;
            }

            $bracketPos = strpos($body, '[', $returnPos);
            if ($bracketPos === false) {
                return null;
            }

            $inner = $this->extractBalancedFrom($body, $bracketPos, '[', ']');
            if ($inner === null) {
                return null;
            }

            $arrayBody = '[' . $inner . ']';

            return $this->parseResourceArrayBodyToExample($arrayBody, $file, $visited, $eagerLoadPaths, $relationPrefix);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse resource toArray() body: scalars, nested new Resource(whenLoaded), and Resource::collection(whenLoaded).
     * Nested relations are only included when their path matches controller with() (see $eagerLoadPaths).
     *
     * @param  array<string, true>  $visited
     * @param  list<string>         $eagerLoadPaths
     */
    private function parseResourceArrayBodyToExample(string $arrayBody, ?string $resourceFilePath, array $visited, array $eagerLoadPaths, string $relationPrefix): ?array
    {
        $scalars = [];
        $nested = [];

        if ($resourceFilePath !== null) {
            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*new\s+([\w\\\\]+)\s*\(\s*\\\$this->whenLoaded\s*\(\s*['\"](\w+)['\"]\s*\)\s*\)/s",
                $arrayBody,
                $nestedMatches,
                PREG_SET_ORDER
            )) {
                foreach ($nestedMatches as $m) {
                    $relationName = $m[3];
                    $fullPath = $this->buildRelationPath($relationPrefix, $relationName);
                    if (! $this->shouldIncludeNestedRelation($fullPath, $eagerLoadPaths, false)) {
                        continue;
                    }
                    $nestedFqcn = $this->resolveShortClassInPhpFile($m[2], $resourceFilePath);
                    if ($nestedFqcn !== null && is_subclass_of($nestedFqcn, JsonResource::class)) {
                        $nestedExample = $this->exampleFromJsonResourceToArray($nestedFqcn, $visited, $eagerLoadPaths, $fullPath);
                        if ($nestedExample !== null) {
                            $nested[$m[1]] = $nestedExample;
                        }
                    }
                }
            }

            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*new\s+([\w\\\\]+)\s*\(\s*\\\$this->(\w+)\s*\)/s",
                $arrayBody,
                $nestedMatches,
                PREG_SET_ORDER
            )) {
                foreach ($nestedMatches as $m) {
                    if (isset($nested[$m[1]])) {
                        continue;
                    }
                    $relationName = $m[3];
                    $fullPath = $this->buildRelationPath($relationPrefix, $relationName);
                    if (! $this->shouldIncludeNestedRelation($fullPath, $eagerLoadPaths, false)) {
                        continue;
                    }
                    $nestedFqcn = $this->resolveShortClassInPhpFile($m[2], $resourceFilePath);
                    if ($nestedFqcn !== null && is_subclass_of($nestedFqcn, JsonResource::class)) {
                        $nestedExample = $this->exampleFromJsonResourceToArray($nestedFqcn, $visited, $eagerLoadPaths, $fullPath);
                        if ($nestedExample !== null) {
                            $nested[$m[1]] = $nestedExample;
                        }
                    }
                }
            }

            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*([\w\\\\]+)::collection\s*\(\s*\\\$this->whenLoaded\s*\(\s*['\"](\w+)['\"]\s*\)\s*\)/s",
                $arrayBody,
                $nestedMatches,
                PREG_SET_ORDER
            )) {
                foreach ($nestedMatches as $m) {
                    if (isset($nested[$m[1]])) {
                        continue;
                    }
                    $relationName = $m[3];
                    $fullPath = $this->buildRelationPath($relationPrefix, $relationName);
                    if (! $this->shouldIncludeNestedRelation($fullPath, $eagerLoadPaths, false)) {
                        continue;
                    }
                    $nestedFqcn = $this->resolveShortClassInPhpFile($m[2], $resourceFilePath);
                    if ($nestedFqcn !== null && is_subclass_of($nestedFqcn, JsonResource::class)) {
                        $nestedExample = $this->exampleFromJsonResourceToArray($nestedFqcn, $visited, $eagerLoadPaths, $fullPath);
                        if ($nestedExample !== null) {
                            $nested[$m[1]] = [$nestedExample];
                        }
                    }
                }
            }

            // UserResource::collection('users') — relation name as string literal (no whenLoaded); allow example without with() in controller.
            if (preg_match_all(
                "/['\"](\w+)['\"]\s*=>\s*([\w\\\\]+)::collection\s*\(\s*['\"](\w+)['\"]\s*\)/s",
                $arrayBody,
                $nestedMatches,
                PREG_SET_ORDER
            )) {
                foreach ($nestedMatches as $m) {
                    if (isset($nested[$m[1]])) {
                        continue;
                    }
                    $relationName = $m[3];
                    $fullPath = $this->buildRelationPath($relationPrefix, $relationName);
                    if (! $this->shouldIncludeNestedRelation($fullPath, $eagerLoadPaths, true)) {
                        continue;
                    }
                    $nestedFqcn = $this->resolveShortClassInPhpFile($m[2], $resourceFilePath);
                    if ($nestedFqcn !== null && is_subclass_of($nestedFqcn, JsonResource::class)) {
                        $nestedExample = $this->exampleFromJsonResourceToArray($nestedFqcn, $visited, $eagerLoadPaths, $fullPath);
                        if ($nestedExample !== null) {
                            $nested[$m[1]] = [$nestedExample];
                        }
                    }
                }
            }
        }

        if (preg_match_all(
            "/['\"](\w+)['\"]\s*=>\s*\\\$this->(\w+)/",
            $arrayBody,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                if (isset($nested[$m[1]])) {
                    continue;
                }
                $scalars[$m[1]] = $this->guessExampleForProperty($m[2]);
            }
        }

        if ($scalars === [] && $nested === []) {
            return null;
        }

        // Scalars first (source order), then relations — matches typical API JSON and OpenAPI examples.
        return array_merge($scalars, $nested);
    }

    private function guessExampleForProperty(string $prop): mixed
    {
        $lower = strtolower($prop);

        if ($lower === 'id' || str_ends_with($lower, '_id')) {
            return 1;
        }

        if (str_contains($lower, 'email')) {
            return 'user@example.com';
        }

        if (str_ends_with($lower, '_at') || $lower === 'date' || str_contains($lower, 'time')) {
            return '2024-01-01T12:00:00.000000Z';
        }

        if (str_contains($lower, 'name') || $lower === 'title' || $lower === 'slug') {
            return 'Example';
        }

        if (str_contains($lower, 'url') || str_contains($lower, 'link')) {
            return 'https://example.com';
        }

        if (str_contains($lower, 'count') || str_contains($lower, 'total') || str_contains($lower, 'number')) {
            return 0;
        }

        if (str_contains($lower, 'active') || str_contains($lower, 'enabled') || str_starts_with($lower, 'is_')) {
            return true;
        }

        return 'string';
    }
}
