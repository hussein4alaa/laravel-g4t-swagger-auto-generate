<?php

namespace G4T\Swagger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable as FillableAttribute;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Derives OpenAPI examples from controller returns: response()->json([...]), foreach-built arrays,
 * plain {@code return [ 'k' => $var ]} shapes, literal arrays/strings, and JsonResource patterns.
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

            $fromWrappedJson = $this->extractFromResponseJsonWrappedResource($body, $file, $eagerLoadPaths);
            if ($fromWrappedJson !== null) {
                return $fromWrappedJson;
            }

            $fromForeachBuiltArray = $this->extractFromResponseJsonForeachBuiltArray($body);
            if ($fromForeachBuiltArray !== null) {
                return $fromForeachBuiltArray;
            }

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

            $composite = $this->extractFromCompositeSectionArrayReturn($body, $class, $file);
            if ($composite !== null) {
                return $composite;
            }

            return $this->extractFromResourceReturn($body, $file, $eagerLoadPaths);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extracts error / non-success JSON examples from the controller: response()->json([...], 4xx/5xx) and abort(4xx, '...').
     *
     * @return array<int, list<array<string, mixed>>> status code => list of example payloads (multiple per status supported)
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

            return $this->mergeErrorExamplesByStatus($fromJson, $fromAbort);
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
     * Opening "(" for the json() argument list in {@code response()->json( … )}, not {@code response( … )}.
     */
    private function locateResponseJsonArgumentListOpenParenFromMatch(string $body, int $responseJsonMatchPos): ?int
    {
        $j = stripos($body, '->json(', $responseJsonMatchPos);
        if ($j === false) {
            return null;
        }

        return $j + 6;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function extractResponseJsonErrorPayloadsFromBody(string $body): array
    {
        $out = [];
        $offset = 0;
        while (($pos = stripos($body, 'response()->json', $offset)) !== false) {
            $openParen = $this->locateResponseJsonArgumentListOpenParenFromMatch($body, $pos);
            if ($openParen === null) {
                $offset = $pos + 1;

                continue;
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
            $status = $this->parseResponseJsonSecondArgumentAsHttpStatus($rest);
            if ($status === null || $status < 400) {
                $offset = $afterClose;

                continue;
            }
            $code = '[' . $inner . ']';
            $code = $this->substituteTranslatorCallsInArrayLiteral($code);
            if (preg_match('/\$|->/', $code)) {
                $offset = $afterClose;

                continue;
            }
            try {
                $result = eval('return ' . $code . ';');
                if (is_array($result)) {
                    $out[$status] ??= [];
                    $out[$status][] = $result;
                }
            } catch (Throwable) {
            }
            $offset = $afterClose;
        }

        return $out;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
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
                $out[$status] ??= [];
                $out[$status][] = ['message' => $msg];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $a
     * @param  array<int, list<array<string, mixed>>>  $b
     * @return array<int, list<array<string, mixed>>>
     */
    private function mergeErrorExamplesByStatus(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $code => $list) {
            if (! isset($out[$code])) {
                $out[$code] = $list;

                continue;
            }
            $out[$code] = array_merge($out[$code], $list);
        }

        return $out;
    }

    /**
     * Second argument to {@code response()->json( first, STATUS )}: literal 4xx/5xx or {@code Response::HTTP_*}.
     *
     * {@code $rest} may start with {@code ,} (text after a literal {@code [...]} first arg) or with the status
     * token (text after {@code scanResponseJsonFirstArgument}, which positions after the comma delimiter).
     */
    private function parseResponseJsonSecondArgumentAsHttpStatus(string $rest): ?int
    {
        $t = ltrim($rest);
        if ($t === '' || $t[0] === ')') {
            return null;
        }
        if ($t[0] === ',') {
            $t = ltrim(substr($t, 1));
        }
        if ($t === '' || $t[0] === ')') {
            return null;
        }
        $chunk = $this->extractTopLevelCommaSeparatedPhpArg($t);
        if ($chunk === null) {
            return null;
        }
        $expr = trim($chunk);
        if (preg_match('/^(\d{3})$/', $expr)) {
            return (int) $expr;
        }
        if (preg_match('/::(HTTP_\w+)\b/', $expr, $cm)) {
            return $this->symfonyResponseStatusByConstantName($cm[1]);
        }

        return null;
    }

    private function responseJsonSecondArgumentIsErrorHttpStatus(string $rest): bool
    {
        $s = $this->parseResponseJsonSecondArgumentAsHttpStatus($rest);

        return $s !== null && $s >= 400;
    }

    /**
     * One PHP argument starting at beginning of $s, ending at top-level ',' or ')'.
     */
    private function extractTopLevelCommaSeparatedPhpArg(string $s): ?string
    {
        $len = strlen($s);
        $dp = 0;
        $db = 0;
        $dbr = 0;
        $inString = false;
        $q = '';
        $start = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($c === '\\' && $i + 1 < $len) {
                    $i++;

                    continue;
                }
                if ($c === $q) {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $inString = true;
                $q = $c;

                continue;
            }
            if ($c === '(') {
                $dp++;
            } elseif ($c === ')') {
                if ($dp === 0 && $db === 0 && $dbr === 0) {
                    return substr($s, $start, $i - $start);
                }
                $dp--;
            } elseif ($c === '[') {
                $db++;
            } elseif ($c === ']') {
                $db--;
            } elseif ($c === '{') {
                $dbr++;
            } elseif ($c === '}') {
                $dbr--;
            } elseif ($c === ',' && $dp === 0 && $db === 0 && $dbr === 0) {
                return substr($s, $start, $i - $start);
            }
        }

        return null;
    }

    private function symfonyResponseStatusByConstantName(string $name): ?int
    {
        try {
            $ref = new ReflectionClass(SymfonyResponse::class);
            $constants = $ref->getConstants();

            return isset($constants[$name]) && is_int($constants[$name]) ? $constants[$name] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Replaces __() / trans() string-literal calls with eval-safe literals so error payloads can be inferred.
     */
    private function substituteTranslatorCallsInArrayLiteral(string $code): string
    {
        $code = preg_replace_callback(
            '/\b__\(\s*([\'"])([^\'"]+)\1\s*\)/',
            function (array $m) {
                $text = __($m[2]);

                return var_export($text, true);
            },
            $code
        ) ?? $code;

        $code = preg_replace_callback(
            '/\btrans\(\s*([\'"])([^\'"]+)\1\s*\)/',
            function (array $m) {
                $text = trans($m[2]);

                return var_export($text, true);
            },
            $code
        ) ?? $code;

        return $code;
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
                    } else {
                        $assoc = $this->associativeArrayLiteralInnerToExampleRow($inner);
                        if ($assoc !== null && $assoc !== []) {
                            return $assoc;
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

    /**
     * Handles {@code return [ [ 'title' => ..., 'data' => $this->sectionData(), ], ... ]} by inferring each
     * {@code $this->method()} return from the callee (e.g. JsonResource::collection) and resolving {@code __()}
     * and {@code $this->getPath('route.name')} at doc-generation time.
     *
     * @return list<array<string, mixed>>|null
     */
    private function extractFromCompositeSectionArrayReturn(string $body, string $controllerClass, string $controllerFile): ?array
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
            if (! str_contains($inner, '$this->')) {
                $offset = $m[0][1] + 1;

                continue;
            }
            $code = '[' . $inner . ']';
            $code = $this->substituteTranslatorCallsInArrayLiteral($code);
            $code = $this->substituteThisGetPathCallsInPhpArray($code);
            $replaced = preg_replace_callback(
                '#\'data\'\s*=>\s*\$this->(\w+)\s*\(\s*\)#',
                function (array $match) use ($controllerClass, $controllerFile) {
                    $inferred = $this->inferExampleFromControllerMethod($controllerClass, $match[1], $controllerFile);
                    if ($inferred === null) {
                        return '\'data\' => []';
                    }

                    return '\'data\' => ' . var_export($inferred, true);
                },
                $code
            );
            if ($replaced === null) {
                $offset = $m[0][1] + 1;

                continue;
            }
            $code = $replaced;
            if (preg_match('/\$|->/', $code)) {
                $offset = $m[0][1] + 1;

                continue;
            }
            try {
                $result = eval('return ' . $code . ';');
                if (is_array($result)) {
                    return $result;
                }
            } catch (Throwable) {
            }
            $offset = $m[0][1] + 1;
        }

        return null;
    }

    private function inferExampleFromControllerMethod(string $controllerClass, string $methodName, string $controllerFile): ?array
    {
        if (! method_exists($controllerClass, $methodName)) {
            return null;
        }

        try {
            $ref = new ReflectionMethod($controllerClass, $methodName);
            $methodFile = $ref->getFileName();
            if (! $methodFile || ! is_readable($methodFile)) {
                return null;
            }
            $lines = file($methodFile);
            if ($lines === false) {
                return null;
            }
            $methodBody = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
            $methodBody = $this->stripFullLineComments($methodBody);
            $eagerLoadPaths = $this->extractEagerLoadPathsFromControllerBody($methodBody);
            $result = $this->extractFromResourceReturn($methodBody, $controllerFile, $eagerLoadPaths);

            return is_array($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolves {@code $this->getPath('named.route')} the same way as a typical controller helper (route + path).
     */
    private function substituteThisGetPathCallsInPhpArray(string $code): string
    {
        $out = preg_replace_callback(
            '#\$this->getPath\s*\(\s*([\'"])([^\'"]+)\1(?:\s*,\s*\[[^\]]*\])?\s*\)#',
            function (array $m) {
                try {
                    $url = route($m[2]);
                    $path = parse_url($url, PHP_URL_PATH);
                    if (! is_string($path) || $path === '') {
                        return var_export('example/path', true);
                    }

                    return var_export(ltrim($path, '/'), true);
                } catch (Throwable) {
                    return var_export('example/path', true);
                }
            },
            $code
        );

        return $out ?? $code;
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
            $openParen = $this->locateResponseJsonArgumentListOpenParenFromMatch($body, $pos);
            if ($openParen === null) {
                $offset = $pos + 1;

                continue;
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
            if ($this->responseJsonSecondArgumentIsErrorHttpStatus($rest)) {
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
     * {@code return response()->json(SomeResource::collection($x))} (and {@code ::make} / {@code new Resource}).
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    private function extractFromResponseJsonWrappedResource(string $body, string $controllerFile, array $eagerLoadPaths): ?array
    {
        $offset = 0;
        while (($pos = stripos($body, 'response()->json', $offset)) !== false) {
            $openParen = $this->locateResponseJsonArgumentListOpenParenFromMatch($body, $pos);
            if ($openParen === null) {
                $offset = $pos + 1;

                continue;
            }
            $scanned = $this->scanResponseJsonFirstArgument($body, $openParen);
            if ($scanned === null) {
                $offset = $pos + 1;

                continue;
            }
            $expr = $scanned['expression'];
            $afterExpr = $scanned['afterExpression'];
            $rest = substr($body, $this->skipWhitespace($body, $afterExpr));
            if ($this->responseJsonSecondArgumentIsErrorHttpStatus($rest)) {
                $offset = $pos + 1;

                continue;
            }

            if (preg_match('/^([\w\\\\]+)::collection\s*\(/', $expr, $m)) {
                $ex = $this->exampleFromResourceClassCollection($m[1], $controllerFile, $eagerLoadPaths, $body);
                if ($ex !== null) {
                    return $ex;
                }
            }
            if (preg_match('/^([\w\\\\]+)::make\s*\(/', $expr, $m)) {
                $fqcn = $this->resolveShortClassInPhpFile($m[1], $controllerFile);
                if ($fqcn !== null && is_subclass_of($fqcn, JsonResource::class)) {
                    $example = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
                    if ($example !== null) {
                        return $this->wrapSingleResourceUnwrapped($example);
                    }
                }
            }
            if (preg_match('/^new\s+([\w\\\\]+)\s*\(/', $expr, $m)) {
                $fqcn = $this->resolveShortClassInPhpFile($m[1], $controllerFile);
                if ($fqcn !== null && is_subclass_of($fqcn, JsonResource::class)) {
                    $example = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
                    if ($example !== null) {
                        return $this->wrapSingleResourceUnwrapped($example);
                    }
                }
            }

            if (preg_match('/^\$(\w+)$/', $expr, $vm)) {
                $rhs = $this->resolveVariableToResourceRhs($body, $vm[1], $pos);
                if ($rhs !== null) {
                    $rhs = trim($rhs);
                    if (preg_match('/^([\w\\\\]+)::collection\s*\(/', $rhs, $m)) {
                        $ex = $this->exampleFromResourceClassCollection($m[1], $controllerFile, $eagerLoadPaths, $body);
                        if ($ex !== null) {
                            return $ex;
                        }
                    }
                    if (preg_match('/^([\w\\\\]+)::make\s*\(/', $rhs, $m)) {
                        $fqcn = $this->resolveShortClassInPhpFile($m[1], $controllerFile);
                        if ($fqcn !== null && is_subclass_of($fqcn, JsonResource::class)) {
                            $example = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
                            if ($example !== null) {
                                return $this->wrapSingleResourceUnwrapped($example);
                            }
                        }
                    }
                    if (preg_match('/^new\s+([\w\\\\]+)\s*\(/', $rhs, $m)) {
                        $fqcn = $this->resolveShortClassInPhpFile($m[1], $controllerFile);
                        if ($fqcn !== null && is_subclass_of($fqcn, JsonResource::class)) {
                            $example = $this->exampleFromJsonResourceToArray($fqcn, [], $eagerLoadPaths, '');
                            if ($example !== null) {
                                return $this->wrapSingleResourceUnwrapped($example);
                            }
                        }
                    }
                }
            }

            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * {@code $rows = []; foreach (...) { $rows[] = [ 'a' => $m->x, ... ]; } return response()->json($rows); }
     *
     * @return list<array<string, mixed>>|null
     */
    private function extractFromResponseJsonForeachBuiltArray(string $body): ?array
    {
        $offset = 0;
        while (($pos = stripos($body, 'response()->json', $offset)) !== false) {
            $openParen = $this->locateResponseJsonArgumentListOpenParenFromMatch($body, $pos);
            if ($openParen === null) {
                $offset = $pos + 1;

                continue;
            }
            $scanned = $this->scanResponseJsonFirstArgument($body, $openParen);
            if ($scanned === null) {
                $offset = $pos + 1;

                continue;
            }
            $expr = trim($scanned['expression']);
            if (! preg_match('/^\$(\w+)$/', $expr, $vm)) {
                $offset = $pos + 1;

                continue;
            }
            $varName = $vm[1];
            $rest = substr($body, $this->skipWhitespace($body, $scanned['afterExpression']));
            if ($this->responseJsonSecondArgumentIsErrorHttpStatus($rest)) {
                $offset = $pos + 1;

                continue;
            }

            $initPattern = '/\$' . preg_quote($varName, '/') . '\s*=\s*\[\s*\]\s*;/';
            if (preg_match($initPattern, $body, $im, PREG_OFFSET_CAPTURE) !== 1) {
                $offset = $pos + 1;

                continue;
            }
            $initEnd = $im[0][1] + strlen($im[0][0]);

            $appendPattern = '/\$' . preg_quote($varName, '/') . '\[\]\s*=\s*\[/';
            if (preg_match($appendPattern, $body, $am, PREG_OFFSET_CAPTURE, $initEnd) !== 1) {
                $offset = $pos + 1;

                continue;
            }
            $bracketStart = $am[0][1] + strlen($am[0][0]) - 1;
            if ($bracketStart >= strlen($body) || $body[$bracketStart] !== '[') {
                $offset = $pos + 1;

                continue;
            }

            $inner = $this->extractBalancedFrom($body, $bracketStart, '[', ']');
            if ($inner === null) {
                $offset = $pos + 1;

                continue;
            }

            $between = substr($body, $initEnd, $am[0][1] - $initEnd);
            if (stripos($between, 'foreach') === false) {
                $offset = $pos + 1;

                continue;
            }

            $row = $this->associativeArrayLiteralInnerToExampleRow($inner);
            if ($row === null || $row === []) {
                $offset = $pos + 1;

                continue;
            }

            return [$row];
        }

        return null;
    }

    /**
     * Parse inner of a PHP array literal {@code 'k' => expr, ...} into example scalars (property access → placeholders).
     *
     * @return array<string, mixed>|null
     */
    private function associativeArrayLiteralInnerToExampleRow(string $inner): ?array
    {
        $inner = trim($inner);
        if ($inner === '') {
            return [];
        }
        $out = [];
        $i = 0;
        $len = strlen($inner);
        while ($i < $len) {
            while ($i < $len && (ctype_space($inner[$i]) || $inner[$i] === ',')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $q = $inner[$i];
            if ($q !== '"' && $q !== "'") {
                return null;
            }
            $i++;
            $key = '';
            while ($i < $len) {
                if ($inner[$i] === '\\') {
                    $key .= $inner[$i + 1] ?? '';
                    $i += 2;

                    continue;
                }
                if ($inner[$i] === $q) {
                    $i++;

                    break;
                }
                $key .= $inner[$i];
                $i++;
            }
            while ($i < $len && ctype_space($inner[$i])) {
                $i++;
            }
            if ($i + 1 >= $len || $inner[$i] !== '=' || $inner[$i + 1] !== '>') {
                return null;
            }
            $i += 2;
            while ($i < $len && ctype_space($inner[$i])) {
                $i++;
            }
            $vStart = $i;
            $dp = 0;
            $db = 0;
            for (; $i < $len; $i++) {
                $c = $inner[$i];
                if ($c === '"' || $c === "'") {
                    $qq = $c;
                    $i++;
                    while ($i < $len) {
                        if ($inner[$i] === '\\') {
                            $i += 2;

                            continue;
                        }
                        if ($inner[$i] === $qq) {
                            break;
                        }
                        $i++;
                    }

                    continue;
                }
                if ($c === '(') {
                    $dp++;
                } elseif ($c === ')') {
                    $dp--;
                } elseif ($c === '[') {
                    $db++;
                } elseif ($c === ']') {
                    $db--;
                }
                if ($c === ',' && $dp === 0 && $db === 0) {
                    break;
                }
            }
            $valueExpr = trim(substr($inner, $vStart, $i - $vStart));
            $out[$key] = $this->phpExpressionToExampleValue($valueExpr, $key);
            if ($i < $len && $inner[$i] === ',') {
                $i++;
            }
        }

        return $out;
    }

    /**
     * Keys that usually map to integers in API payloads (counts, pagination, etc.).
     */
    private function arrayKeySuggestsNumericExample(string $key): bool
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

    private function phpExpressionToExampleValue(string $expr, ?string $associativeArrayKey = null): mixed
    {
        $expr = trim($expr);
        if ($expr === '') {
            return 'Example';
        }
        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        if (preg_match('/^-?\d+\.\d+$/', $expr)) {
            return (float) $expr;
        }
        $lower = strtolower($expr);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (($expr[0] ?? '') === '"' || ($expr[0] ?? '') === "'") {
            $lit = $this->extractStringLiteralExpression($expr, 0);
            if ($lit !== null) {
                try {
                    $v = eval('return ' . $lit . ';');
                    if (is_string($v)) {
                        return $v;
                    }
                } catch (Throwable) {
                }
            }
        }
        if (preg_match('/->\s*id\b/', $expr)) {
            return 1;
        }
        if (str_contains($expr, 'getTranslation')) {
            return 'Example';
        }
        if (preg_match('/->/', $expr)) {
            return 'Example';
        }
        if (preg_match('/^\$(\w+)$/', $expr) && $associativeArrayKey !== null && $this->arrayKeySuggestsNumericExample($associativeArrayKey)) {
            return 0;
        }
        if (str_starts_with($expr, '$')) {
            return 'Example';
        }

        return 'Example';
    }

    /**
     * {@code $response = new SomeResource(...); return response()->json($response);} — resolve RHS of the last assignment before the json() call.
     */
    private function resolveVariableToResourceRhs(string $body, string $varName, int $beforePos): ?string
    {
        if ($beforePos <= 0) {
            return null;
        }

        $prefix = substr($body, 0, $beforePos);
        $pattern = '/\$'.preg_quote($varName, '/').'\s*=\s*/';

        if (preg_match_all($pattern, $prefix, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $last = end($matches[0]);
        $valueStart = $last[1] + strlen($last[0]);

        return $this->extractAssignmentRhsExpression($body, $valueStart);
    }

    /**
     * PHP statement RHS from the first non-whitespace char after "=" until ";" at nesting depth 0.
     */
    private function extractAssignmentRhsExpression(string $body, int $start): ?string
    {
        $len = strlen($body);
        $i = $this->skipWhitespace($body, $start);
        if ($i >= $len) {
            return null;
        }

        $startExpr = $i;
        $depthParen = 0;
        $depthSq = 0;
        $depthBrace = 0;
        $inString = false;
        $q = '';

        for (; $i < $len; $i++) {
            $c = $body[$i];

            if ($inString) {
                if ($c === '\\' && $i + 1 < $len) {
                    $i++;

                    continue;
                }
                if ($c === $q) {
                    $inString = false;
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $inString = true;
                $q = $c;

                continue;
            }

            if ($c === '[') {
                $depthSq++;

                continue;
            }
            if ($c === ']') {
                $depthSq = max(0, $depthSq - 1);

                continue;
            }
            if ($c === '{') {
                $depthBrace++;

                continue;
            }
            if ($c === '}') {
                $depthBrace = max(0, $depthBrace - 1);

                continue;
            }
            if ($c === '(') {
                $depthParen++;

                continue;
            }
            if ($c === ')') {
                $depthParen = max(0, $depthParen - 1);

                continue;
            }

            if ($c === ';' && $depthParen === 0 && $depthSq === 0 && $depthBrace === 0) {
                return trim(substr($body, $startExpr, $i - $startExpr));
            }
        }

        return null;
    }

    /**
     * First argument to {@code response()->json( HERE )}, respecting nested (), [], {}, and strings.
     *
     * @return array{expression: string, afterExpression: int}|null
     */
    private function scanResponseJsonFirstArgument(string $body, int $openParenOfJsonCall): ?array
    {
        $len = strlen($body);
        $i = $this->skipWhitespace($body, $openParenOfJsonCall + 1);
        if ($i >= $len) {
            return null;
        }
        $start = $i;
        $depthParen = 0;
        $depthSq = 0;
        $depthBrace = 0;
        $inString = false;
        $q = '';
        for (; $i < $len; $i++) {
            $c = $body[$i];
            if ($inString) {
                if ($c === '\\' && $i + 1 < $len) {
                    $i++;

                    continue;
                }
                if ($c === $q) {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $inString = true;
                $q = $c;

                continue;
            }
            if ($c === '[') {
                $depthSq++;

                continue;
            }
            if ($c === ']') {
                $depthSq = max(0, $depthSq - 1);

                continue;
            }
            if ($c === '{') {
                $depthBrace++;

                continue;
            }
            if ($c === '}') {
                $depthBrace = max(0, $depthBrace - 1);

                continue;
            }
            if ($c === '(') {
                $depthParen++;

                continue;
            }
            if ($c === ')') {
                if ($depthParen > 0) {
                    $depthParen--;

                    continue;
                }
                if ($depthSq === 0 && $depthBrace === 0) {
                    return [
                        'expression' => trim(substr($body, $start, $i - $start)),
                        'afterExpression' => $i + 1,
                    ];
                }
            }
            if ($c === ',' && $depthParen === 0 && $depthSq === 0 && $depthBrace === 0) {
                return [
                    'expression' => trim(substr($body, $start, $i - $start)),
                    'afterExpression' => $i + 1,
                ];
            }
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
