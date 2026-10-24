<?php

namespace G4T\Swagger;

use G4T\Swagger\Inference\CodeScanner;
use G4T\Swagger\Inference\ExampleGuesser;
use G4T\Swagger\Inference\LiteralEvaluator;
use G4T\Swagger\Inference\ModelInspector;
use G4T\Swagger\Inference\PhpSource;
use G4T\Swagger\Inference\Unresolved;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\MissingValue;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Derives OpenAPI examples from controller source without executing it: response()->json([...]) / new
 * JsonResponse(...) payloads, foreach-built arrays, plain {@code return [...]} shapes, literal strings, and
 * JsonResource patterns (typed through Eloquent casts), plus error payloads from abort() / HTTP exceptions.
 *
 * Source is always searched on a masked copy (comments removed, string contents blanked) so code inside
 * strings or comments is never matched; values are produced by {@see LiteralEvaluator} (no eval()).
 * One instance is reused for a whole generation run, so parsed files, methods and examples are memoised.
 */
class ResponseExampleExtractor
{
    /** Maximum nesting of resources inside resources. */
    private const MAX_RESOURCE_DEPTH = 3;

    /** How many times the same resource class may appear among the ancestors of a nested resource. */
    private const MAX_SAME_RESOURCE_ANCESTORS = 1;

    private const RESOURCE_CALL = '/^\(*\s*(?:new\s+(\\\\?[A-Za-z_][\w\\\\]*)\s*\(|(\\\\?[A-Za-z_][\w\\\\]*)\s*::\s*(make|collection)\s*\()/';

    private PhpSource $source;

    private ModelInspector $models;

    /** @var array<string, array<string, mixed>|null> */
    private array $contexts = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $resourceExamples = [];

    /** @var array<string, string|null> */
    private array $resourceModels = [];

    /** @var array<string, LiteralEvaluator> */
    private array $evaluators = [];

    private int $methodInferenceDepth = 0;

    private ?string $currentAction = null;

    public function __construct()
    {
        $this->source = new PhpSource();
        $this->models = new ModelInspector(function (string $message, Throwable $e): void {
            $this->debug($message, $e);
        });
    }

    public function extract(string $controllerAction): array|string|null
    {
        $ctx = $this->actionContext($controllerAction);
        if ($ctx === null) {
            return null;
        }

        try {
            return $this->successExample($ctx);
        } catch (Throwable $e) {
            $this->debug('success example inference failed', $e);

            return null;
        }
    }

    /**
     * Extracts error / non-success JSON examples from the controller: response()->json([...], 4xx/5xx),
     * new JsonResponse(...), abort()/abort_if()/abort_unless() and thrown HTTP exceptions.
     *
     * @return array<int, list<array<string, mixed>>> status code => list of example payloads
     */
    public function extractErrorResponseExamples(string $controllerAction): array
    {
        $ctx = $this->actionContext($controllerAction);
        if ($ctx === null) {
            return [];
        }

        try {
            $out = [];
            foreach ($this->jsonCalls($ctx) as $call) {
                $status = $this->jsonCallStatus($ctx, $call);
                if ($status === null || $status < 400 || $call['data'] === null) {
                    continue;
                }
                $payload = $this->payloadExample($ctx, $call['data']['orig'], $call['data']['masked'], $call['offset']);
                if (is_array($payload) && $payload !== []) {
                    $out[$status][] = $payload;
                }
            }

            foreach ([$this->abortExamples($ctx), $this->thrownExceptionExamples($ctx)] as $examples) {
                foreach ($examples as [$status, $payload]) {
                    $out[$status][] = $payload;
                }
            }

            foreach ($out as $status => $payloads) {
                $unique = [];
                foreach ($payloads as $payload) {
                    $unique[serialize($payload)] = $payload;
                }
                $out[$status] = array_values($unique);
            }
            ksort($out);

            return $out;
        } catch (Throwable $e) {
            $this->debug('error example inference failed', $e);

            return [];
        }
    }

    /**
     * HTTP status of the successful response: 201 for json(..., 201) / Response::HTTP_CREATED / status: 201
     * or a JsonResource returned from a "store" action, 204 for response()->noContent() as the only success
     * return, otherwise 200.
     */
    public function inferSuccessStatus(string $controllerAction): int
    {
        $ctx = $this->actionContext($controllerAction);
        if ($ctx === null) {
            return 200;
        }

        try {
            foreach ($this->jsonCalls($ctx) as $call) {
                $status = $this->jsonCallStatus($ctx, $call);
                if ($status !== null && $status < 400) {
                    return $status;
                }
            }

            $noContent = false;
            $otherSuccess = false;
            foreach ($this->returnStatements($ctx) as $return) {
                $m = $return['masked'];
                if ($this->isJsonFactoryExpression($m)) {
                    continue;
                }
                if (preg_match('/->\s*setStatusCode\s*\(/', $m, $sm, PREG_OFFSET_CAPTURE)) {
                    $args = CodeScanner::arguments($return['orig'], $m, $sm[0][1] + strlen($sm[0][0]) - 1);
                    $status = isset($args[0]) ? $this->resolveStatusExpression($ctx, $args[0]['orig'], $args[0]['masked'], $return['offset']) : null;
                    if ($status !== null && $status < 400) {
                        return $status;
                    }
                }
                if (preg_match('/^response\s*\(\s*\)\s*->\s*noContent\s*\(/', $m)) {
                    $noContent = true;

                    continue;
                }
                if (preg_match('/^response\s*\(/', $m, $rm)) {
                    $args = CodeScanner::arguments($return['orig'], $m, strlen($rm[0]) - 1);
                    $statusArg = CodeScanner::argument($args, 1, 'status');
                    $status = $statusArg === null ? 200 : $this->resolveStatusExpression($ctx, $statusArg['orig'], $statusArg['masked'], $return['offset']);
                    if ($status === 204) {
                        $noContent = true;

                        continue;
                    }
                    if ($status !== null && $status < 400) {
                        return $status;
                    }

                    continue;
                }
                if (strtolower($ctx['method']) === 'store' && $this->resourceCallKind($ctx, $m) === 'single') {
                    return 201;
                }
                $otherSuccess = true;
            }

            return $noContent && ! $otherSuccess ? 204 : 200;
        } catch (Throwable $e) {
            $this->debug('success status inference failed', $e);

            return 200;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function actionContext(string $controllerAction): ?array
    {
        if ($controllerAction === 'Closure' || ! str_contains($controllerAction, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $controllerAction, 2);
        $this->currentAction = $controllerAction;

        try {
            if (! class_exists($class) || ! method_exists($class, $method)) {
                return null;
            }

            return $this->methodContext($class, $method);
        } catch (Throwable $e) {
            $this->debug('could not load controller method', $e);

            return null;
        }
    }

    /**
     * Parsed method: original + masked source of the method, body start, closure ranges, parameter types,
     * eager-loaded relations and counts. Imports are resolved against the file declaring the method
     * (trait / parent class file included).
     *
     * @return array<string, mixed>|null
     */
    private function methodContext(string $class, string $method): ?array
    {
        $key = $class . '@' . $method;
        if (array_key_exists($key, $this->contexts)) {
            return $this->contexts[$key];
        }
        $this->contexts[$key] = null;

        $ref = new ReflectionMethod($class, $method);
        $file = $ref->getFileName();
        if (! $file || $ref->getStartLine() === false || $ref->getEndLine() === false) {
            return null;
        }
        $slice = $this->source->lineSlice($file, $ref->getStartLine(), $ref->getEndLine());
        if ($slice === null) {
            return null;
        }
        [$body, $masked] = $slice;

        $signature = preg_match('/\bfunction\s+&?\s*' . preg_quote($method, '/') . '\s*\(/i', $masked, $fm, PREG_OFFSET_CAPTURE)
            ? $fm[0][1] + strlen($fm[0][0]) - 1
            : strpos($masked, '(');
        $paramsClose = $signature === false ? null : CodeScanner::matchClose($masked, $signature);
        $bodyStart = $paramsClose === null ? false : strpos($masked, '{', $paramsClose);
        if ($bodyStart === false) {
            return null;
        }

        $params = [];
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            $params[$param->getName()] = $type instanceof ReflectionNamedType ? $type->getName() : null;
        }

        [$eager, $counts] = $this->eagerLoads($body, $masked);

        return $this->contexts[$key] = [
            'class' => $ref->getDeclaringClass()->getName(),
            'method' => $method,
            'file' => $file,
            'body' => $body,
            'masked' => $masked,
            'bodyStart' => $bodyStart,
            'closures' => CodeScanner::closureRanges($masked, $bodyStart + 1),
            'params' => $params,
            'eager' => $eager,
            'counts' => $counts,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function successExample(array $ctx): array|string|null
    {
        foreach ($this->jsonCalls($ctx) as $call) {
            $status = $this->jsonCallStatus($ctx, $call);
            if ($status === null || $status >= 400 || $call['data'] === null) {
                continue;
            }
            $example = $this->payloadExample($ctx, $call['data']['orig'], $call['data']['masked'], $call['offset']);
            if ($example !== null) {
                return $example;
            }
        }

        $returns = $this->returnStatements($ctx);

        foreach ($returns as $return) {
            $m = $return['masked'];
            if ($m[0] === '[' || preg_match('/^array\s*\(/i', $m)) {
                $value = $this->evaluateValue($ctx, $return['orig']);
                if (is_array($value)) {
                    return $value;
                }
            } elseif ($m[0] === '"' || $m[0] === "'") {
                $value = $this->evaluator($ctx, false)->evaluateStrict($return['orig']);
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        foreach ($returns as $return) {
            if ($this->isJsonFactoryExpression($return['masked'])) {
                continue;
            }
            $example = $this->resourceExampleFromExpression($ctx, $return['orig'], $return['masked']);
            if ($example !== null) {
                return $example;
            }
            if (preg_match('/^\$(\w+)$/', $return['masked'], $vm) && $vm[1] !== 'this') {
                $rhs = $this->assignmentRhs($ctx, $vm[1], $return['offset']);
                if ($rhs !== null && ! preg_match('/^\$\w+$/', $rhs['masked'])) {
                    $example = $this->payloadExample($ctx, $rhs['orig'], $rhs['masked'], $rhs['offset']);
                    if ($example !== null) {
                        return $example;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Top-level (non-closure) return statements of a method, in source order.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{orig: string, masked: string, offset: int, depth: int}>
     */
    private function returnStatements(array $ctx): array
    {
        $masked = $ctx['masked'];
        $start = $ctx['bodyStart'];
        if (! preg_match_all('/(?<![\w$>:])return\b/', $masked, $m, PREG_OFFSET_CAPTURE, $start)) {
            return [];
        }

        $out = [];
        foreach ($m[0] as [, $pos]) {
            if (CodeScanner::inRanges($pos, $ctx['closures'])) {
                continue;
            }
            $exprStart = $pos + 6;
            $exprEnd = CodeScanner::expressionEnd($masked, $exprStart);
            $expr = substr($masked, $exprStart, $exprEnd - $exprStart);
            $lead = strlen($expr) - strlen(ltrim($expr));
            $expr = trim($expr);
            if ($expr === '') {
                continue;
            }
            $segment = substr($masked, $start, $pos - $start);
            $out[] = [
                'orig' => substr($ctx['body'], $exprStart + $lead, strlen($expr)),
                'masked' => $expr,
                'offset' => $exprStart + $lead,
                'depth' => substr_count($segment, '{') - substr_count($segment, '}'),
            ];
        }

        return $out;
    }

    /**
     * response()->json(...), new JsonResponse(...) and Response::json(...) calls with their data / status args.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{offset: int, data: ?array{orig: string, masked: string}, status: ?array{orig: string, masked: string}}>
     */
    private function jsonCalls(array $ctx): array
    {
        $pattern = '/(?<![\w$>\\\\])(?:response\s*\(\s*\)\s*->\s*json|new\s+\\\\?(?:Illuminate\\\\Http\\\\)?JsonResponse|Response\s*::\s*json)\s*\(/';
        if (! preg_match_all($pattern, $ctx['masked'], $m, PREG_OFFSET_CAPTURE, $ctx['bodyStart'])) {
            return [];
        }

        $calls = [];
        foreach ($m[0] as [$text, $pos]) {
            $args = CodeScanner::arguments($ctx['body'], $ctx['masked'], $pos + strlen($text) - 1);
            $data = CodeScanner::argument($args, 0, 'data');
            $status = CodeScanner::argument($args, 1, 'status');
            $calls[] = [
                'offset' => $pos,
                'data' => $data === null ? null : ['orig' => $data['orig'], 'masked' => $data['masked']],
                'status' => $status === null ? null : ['orig' => $status['orig'], 'masked' => $status['masked']],
            ];
        }

        return $calls;
    }

    /**
     * 200 when the call has no status argument, the resolved status, or null when it cannot be resolved.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array{offset: int, status: ?array{orig: string, masked: string}}  $call
     */
    private function jsonCallStatus(array $ctx, array $call): ?int
    {
        if ($call['status'] === null) {
            return 200;
        }

        return $this->resolveStatusExpression($ctx, $call['status']['orig'], $call['status']['masked'], $call['offset']);
    }

    /**
     * Literal status, class constant (Response::HTTP_*), or a local variable assigned one of those.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function resolveStatusExpression(array $ctx, string $orig, string $masked, int $beforeOffset, int $depth = 0): ?int
    {
        $masked = trim($masked);
        if (preg_match('/^\$(\w+)$/', $masked, $vm)) {
            if ($depth > 0) {
                return null;
            }
            $rhs = $this->assignmentRhs($ctx, $vm[1], $beforeOffset);

            return $rhs === null ? null : $this->resolveStatusExpression($ctx, $rhs['orig'], $rhs['masked'], $rhs['offset'], $depth + 1);
        }

        $value = $this->evaluator($ctx, false)->evaluateStrict($orig);
        if (! is_int($value) && preg_match('/::\s*(HTTP_\w+)$/', $masked, $cm)) {
            $value = $this->symfonyStatus($cm[1]);
        }

        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }

    private function symfonyStatus(string $constant): ?int
    {
        $name = SymfonyResponse::class . '::' . $constant;

        return defined($name) && is_int(constant($name)) ? constant($name) : null;
    }

    /**
     * Right-hand side of the last {@code $var = ...;} before $beforeOffset.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{orig: string, masked: string, offset: int}|null
     */
    private function assignmentRhs(array $ctx, string $varName, int $beforeOffset): ?array
    {
        $masked = $ctx['masked'];
        $prefix = substr($masked, 0, $beforeOffset);
        if (! preg_match_all('/\$' . preg_quote($varName, '/') . '\s*=(?![=>])/', $prefix, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($m[0]);
        $start = $last[1] + strlen($last[0]);
        $end = CodeScanner::expressionEnd($masked, $start);
        $expr = substr($masked, $start, $end - $start);
        $lead = strlen($expr) - strlen(ltrim($expr));
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        return ['orig' => substr($ctx['body'], $start + $lead, strlen($expr)), 'masked' => $expr, 'offset' => $start + $lead];
    }

    /**
     * Example for a response payload expression (array literal, resource, variable, string literal).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function payloadExample(array $ctx, string $orig, string $masked, int $offset, int $depth = 0): array|string|null
    {
        $masked = trim($masked);
        $orig = trim($orig);
        if ($masked === '') {
            return null;
        }

        if (preg_match('/^\$(\w+)$/', $masked, $vm)) {
            if ($vm[1] === 'this' || $depth > 0) {
                return null;
            }
            $rows = $this->foreachBuiltArrayExample($ctx, $vm[1]);
            if ($rows !== null) {
                return $rows;
            }
            $rhs = $this->assignmentRhs($ctx, $vm[1], $offset);

            return $rhs === null ? null : $this->payloadExample($ctx, $rhs['orig'], $rhs['masked'], $rhs['offset'], $depth + 1);
        }

        $resource = $this->resourceExampleFromExpression($ctx, $orig, $masked);
        if ($resource !== null) {
            return $resource;
        }

        if ($masked[0] === '[' || preg_match('/^array\s*\(/i', $masked)) {
            $value = $this->evaluateValue($ctx, $orig);

            return is_array($value) ? $value : null;
        }

        $value = $this->evaluator($ctx, false)->evaluateStrict($orig);

        return is_string($value) || is_array($value) ? $value : null;
    }

    /**
     * {@code $rows = []; foreach (...) { $rows[] = [ 'a' => $m->x, ... ]; } return response()->json($rows); }
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>|null
     */
    private function foreachBuiltArrayExample(array $ctx, string $varName): ?array
    {
        $masked = $ctx['masked'];
        $var = preg_quote($varName, '/');
        if (preg_match('/\$' . $var . '\s*=\s*(?:\[\s*\]|array\s*\(\s*\))\s*;/i', $masked, $im, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $initEnd = $im[0][1] + strlen($im[0][0]);
        if (preg_match('/\$' . $var . '\s*\[\s*\]\s*=\s*\[/', $masked, $am, PREG_OFFSET_CAPTURE, $initEnd) !== 1) {
            return null;
        }
        if (stripos(substr($masked, $initEnd, $am[0][1] - $initEnd), 'foreach') === false) {
            return null;
        }
        $open = $am[0][1] + strlen($am[0][0]) - 1;
        $close = CodeScanner::matchClose($masked, $open);
        if ($close === null) {
            return null;
        }
        $row = $this->evaluateValue($ctx, substr($ctx['body'], $open, $close - $open + 1));

        return is_array($row) && $row !== [] ? [$row] : null;
    }

    /**
     * Evaluates an expression of the method; non-literal parts become placeholders / resource examples.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function evaluateValue(array $ctx, string $orig, ?string $key = null): mixed
    {
        return $this->evaluator($ctx, true)->evaluate($orig, $key);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function evaluator(array $ctx, bool $withFallback): LiteralEvaluator
    {
        $key = implode('|', [
            $ctx['class'] . '@' . $ctx['method'],
            $ctx['resource'] ?? '',
            $ctx['prefix'] ?? '',
            implode('>', $ctx['stack'] ?? []),
            implode(',', $ctx['eager']),
            implode(',', $ctx['counts']),
            $ctx['model'] ?? '',
            $withFallback ? '1' : '0',
        ]);
        if (isset($this->evaluators[$key])) {
            return $this->evaluators[$key];
        }

        $classResolver = fn (string $name): ?string => $this->resolveClass($ctx, $name);
        $fallback = null;
        if ($withFallback) {
            $fallback = isset($ctx['resource'])
                ? fn (string $expr, ?string $key): mixed => $this->resourceValue($ctx, $expr, $key)
                : fn (string $expr, ?string $key): mixed => $this->placeholderValue($ctx, $expr, $key);
        }

        return $this->evaluators[$key] = new LiteralEvaluator($classResolver, $fallback);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function resolveClass(array $ctx, string $name): ?string
    {
        $lower = strtolower(ltrim($name, '\\'));
        if ($lower === 'self' || $lower === 'static') {
            return $ctx['class'];
        }
        if ($lower === 'parent') {
            return get_parent_class($ctx['class']) ?: null;
        }

        return $this->source->resolveClass($name, $ctx['file']);
    }

    /**
     * Placeholder example for a non-literal expression inside a controller payload.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function placeholderValue(array $ctx, string $expr, ?string $key): mixed
    {
        $orig = trim($expr);
        $masked = trim(PhpSource::mask($orig));
        if ($masked === '') {
            return $this->guess($key);
        }

        $resource = $this->resourceExampleFromExpression($ctx, $orig, $masked);
        if ($resource !== null) {
            return $resource;
        }

        if (preg_match('/^\$this\s*->\s*getPath\s*\(/', $masked, $gm)) {
            $args = CodeScanner::arguments($orig, $masked, strlen($gm[0]) - 1);
            $route = isset($args[0]) ? $this->evaluator($ctx, false)->evaluateStrict($args[0]['orig']) : null;

            return is_string($route) ? $this->routePath($route) : 'example/path';
        }

        if (preg_match('/^\$this\s*->\s*(\w+)\s*\(\s*\)$/', $masked, $mm) && method_exists($ctx['class'], $mm[1])) {
            $inferred = $this->inferExampleFromControllerMethod($ctx['class'], $mm[1]);
            if ($inferred !== null) {
                return $inferred;
            }
            if ($key === 'data') {
                return [];
            }
        }

        $typed = $this->typedExpressionExample($masked, $key);
        if (! $typed instanceof Unresolved) {
            return $typed;
        }

        if (preg_match('/^\$(\w+)$/', $masked, $vm)) {
            $type = $ctx['params'][$vm[1]] ?? null;
            if (in_array($type, ['int', 'float', 'bool'], true)) {
                return match ($type) {
                    'int' => 1,
                    'float' => 10.5,
                    default => true,
                };
            }
            $model = $this->modelForVariable($ctx, $vm[1]);
            if ($model !== null) {
                $row = $this->models->modelExample($model);
                if ($row !== null) {
                    return $row;
                }
            }
            if ($key !== null && ExampleGuesser::keySuggestsNumber($key)) {
                return 0;
            }

            return $this->guess($key);
        }

        if (preg_match('/^\$(\w+)\s*\??->\s*(\w+)$/', $masked, $pm)) {
            $model = $this->modelForVariable($ctx, $pm[1]);
            if ($model !== null) {
                $value = $this->models->attributeExample($model, $pm[2]);
                if (! $value instanceof Unresolved) {
                    return $value;
                }
            }
            $guess = ExampleGuesser::property($pm[2]);

            return $guess === 'string' ? 'Example' : $guess;
        }

        if (str_contains($masked, '->')) {
            $guess = $this->guess($key);

            return $guess === 'string' ? 'Example' : $guess;
        }

        return $this->guess($key);
    }

    /**
     * Type hints that do not depend on context: casts, counts, aggregates, date formatting, URL helpers.
     */
    private function typedExpressionExample(string $masked, ?string $key): mixed
    {
        if (preg_match('/^\(\s*(int|integer|float|double|real|bool|boolean|string|array|object)\s*\)/i', $masked, $cm)) {
            return match (strtolower($cm[1])) {
                'int', 'integer' => 1,
                'float', 'double', 'real' => 10.5,
                'bool', 'boolean' => true,
                'array', 'object' => ExampleGuesser::collection($key ?? 'data'),
                default => $key !== null && is_string($guess = ExampleGuesser::property($key)) ? $guess : 'string',
            };
        }
        if (preg_match('/^\\\\?count\s*\(/', $masked) || preg_match('/->\s*count\s*\(\s*\)$/', $masked)) {
            return 1;
        }
        if (preg_match('/->\s*(sum|avg|average|min|max)\s*\([^()]*\)$/', $masked)) {
            return 10.5;
        }
        if (preg_match('/->\s*(exists|doesntExist)\s*\(\s*\)$/', $masked)) {
            return true;
        }
        if (preg_match('/\??->\s*(toDateString)\s*\(\s*\)$/', $masked)) {
            return '2024-01-01';
        }
        if (preg_match('/\??->\s*(toIso8601String|toISOString|toDateTimeString|toAtomString|toJSON|format)\s*\(/', $masked)) {
            return ExampleGuesser::DATETIME;
        }
        if (preg_match('/^(?:\\\\?(?:Illuminate\\\\Support\\\\Facades\\\\)?Storage\s*::|\\\\?(?:asset|secure_asset|url|route)\s*\()/', $masked)
            || preg_match('/->\s*(?:url|temporaryUrl|getUrl|getFullUrl|getFirstMediaUrl)\s*\(/', $masked)) {
            return ExampleGuesser::URL . '/storage/example.jpg';
        }

        return Unresolved::instance();
    }

    private function guess(?string $key): mixed
    {
        return $key === null || $key === '' ? 'string' : ExampleGuesser::property($key);
    }

    /**
     * {@code $user} → App\Models\User when that model is imported / in scope (or the parameter is typed with it).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function modelForVariable(array $ctx, string $varName): ?string
    {
        $typed = $ctx['params'][$varName] ?? null;
        if ($typed !== null && $this->models->isModel($typed)) {
            return $typed;
        }

        $candidate = $this->resolveClass($ctx, ucfirst($varName));

        return $candidate !== null && $this->models->isModel($candidate) ? $candidate : null;
    }

    private function routePath(string $route): string
    {
        try {
            $path = parse_url(route($route), PHP_URL_PATH);

            return is_string($path) && $path !== '' ? ltrim($path, '/') : 'example/path';
        } catch (Throwable) {
            return 'example/path';
        }
    }

    /**
     * Example of the first resource-returning statement of another controller method ($this->method()).
     */
    private function inferExampleFromControllerMethod(string $controllerClass, string $methodName): ?array
    {
        if ($this->methodInferenceDepth > 3) {
            return null;
        }

        $this->methodInferenceDepth++;
        try {
            $ctx = $this->methodContext($controllerClass, $methodName);
            if ($ctx === null) {
                return null;
            }
            foreach ($this->returnStatements($ctx) as $return) {
                $example = $this->resourceExampleFromExpression($ctx, $return['orig'], $return['masked']);
                if ($example !== null) {
                    return $example;
                }
            }

            return null;
        } catch (Throwable $e) {
            $this->debug('could not infer ' . $controllerClass . '::' . $methodName, $e);

            return null;
        } finally {
            $this->methodInferenceDepth--;
        }
    }

    private function isJsonFactoryExpression(string $masked): bool
    {
        return (bool) preg_match('/^(?:response\s*\(\s*\)\s*->\s*json\s*\(|new\s+\\\\?(?:Illuminate\\\\Http\\\\)?JsonResponse\s*\(|Response\s*::\s*json\s*\()/', $masked);
    }

    /**
     * "single" / "collection" when the expression constructs a JsonResource, else null.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function resourceCallKind(array $ctx, string $masked): ?string
    {
        if (! preg_match(self::RESOURCE_CALL, $masked, $m)) {
            return null;
        }
        $fqcn = $this->resolveClass($ctx, $m[1] !== '' ? $m[1] : $m[2]);
        if ($fqcn === null || ! is_subclass_of($fqcn, JsonResource::class)) {
            return null;
        }

        return ($m[3] ?? '') === 'collection' || is_subclass_of($fqcn, ResourceCollection::class) ? 'collection' : 'single';
    }

    /**
     * {@code new XResource(..)} / {@code XResource::make(..)} → resource example (unwrapped);
     * {@code XResource::collection(..)} / {@code new XCollection(..)} → wrapped collection (data / pagination).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<int|string, mixed>|null
     */
    private function resourceExampleFromExpression(array $ctx, string $orig, string $masked): ?array
    {
        if (! preg_match(self::RESOURCE_CALL, ltrim($masked), $m)) {
            return null;
        }
        $fqcn = $this->resolveClass($ctx, $m[1] !== '' ? $m[1] : $m[2]);
        if ($fqcn === null || ! is_subclass_of($fqcn, JsonResource::class)) {
            return null;
        }

        $isCollection = ($m[3] ?? '') === 'collection';
        if (is_subclass_of($fqcn, ResourceCollection::class)) {
            $fqcn = $this->collectedResource($fqcn);
            $isCollection = true;
            if ($fqcn === null) {
                return null;
            }
        }

        $example = $this->resourceExample($fqcn, $ctx['eager'], $ctx['counts'], '', [], $this->modelHint($ctx));
        if ($example === null) {
            return null;
        }

        if (! $isCollection) {
            return $example;
        }

        $paginationType = $this->detectPaginationType($ctx['masked']);
        if ($paginationType !== 'none') {
            return $this->buildPaginatedCollectionExample($example, $paginationType);
        }

        return $this->wrapNonPaginatedCollection($example);
    }

    /**
     * Item resource of a ResourceCollection subclass ($collects, or FooCollection → FooResource / Foo).
     */
    private function collectedResource(string $collectionClass): ?string
    {
        try {
            $collects = (new ReflectionClass($collectionClass))->getDefaultProperties()['collects'] ?? null;
            if (is_string($collects) && is_subclass_of($collects, JsonResource::class)) {
                return $collects;
            }
            foreach ([preg_replace('/Collection$/', 'Resource', $collectionClass), preg_replace('/Collection$/', '', $collectionClass)] as $candidate) {
                if (is_string($candidate) && $candidate !== $collectionClass && class_exists($candidate)
                    && is_subclass_of($candidate, JsonResource::class) && ! is_subclass_of($candidate, ResourceCollection::class)) {
                    return $candidate;
                }
            }
        } catch (Throwable $e) {
            $this->debug('could not resolve collected resource of ' . $collectionClass, $e);
        }

        return null;
    }

    /**
     * First Eloquent model the controller method works with (typed parameter, then Model::... calls).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function modelHint(array $ctx): ?string
    {
        foreach ($ctx['params'] as $type) {
            if ($type !== null && $this->models->isModel($type)) {
                return $type;
            }
        }

        if (preg_match_all('/(?<![\w$>\\\\])(\\\\?[A-Z][\w\\\\]*)\s*::\s*(?!class\b)[a-z_]\w*\s*\(/', $ctx['masked'], $m, 0, $ctx['bodyStart'])) {
            foreach (array_unique($m[1]) as $name) {
                $fqcn = $this->resolveClass($ctx, $name);
                if ($fqcn !== null && $this->models->isModel($fqcn)) {
                    return $fqcn;
                }
            }
        }

        return null;
    }

    /**
     * Collects relation paths and counts from eager loads in a method: with / load / loadMissing (paths)
     * and withCount / loadCount (counts), via -> or ::, all string args, array args and 'rel' => fn keys.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function eagerLoads(string $orig, string $masked): array
    {
        $paths = [];
        $counts = [];
        if (! preg_match_all('/(?:->|::)\s*(with|load|loadMissing|withCount|loadCount)\s*\(/', $masked, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [[], []];
        }

        $evaluator = new LiteralEvaluator();
        foreach ($m as $match) {
            $isCount = in_array($match[1][0], ['withCount', 'loadCount'], true);
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $names = [];
            foreach (CodeScanner::arguments($orig, $masked, $open) as $arg) {
                $am = $arg['masked'];
                if ($am[0] === '"' || $am[0] === "'") {
                    $names[] = $evaluator->evaluateStrict($arg['orig']);

                    continue;
                }
                if (! preg_match('/^(\[|array\s*\()/i', $am, $bm)) {
                    continue;
                }
                $arrayOpen = $arg['offset'] + strlen($bm[1]) - 1;
                $arrayClose = CodeScanner::matchClose($masked, $arrayOpen);
                if ($arrayClose === null) {
                    continue;
                }
                foreach (CodeScanner::splitTopLevel($masked, $arrayOpen + 1, $arrayClose) as [$s, $e]) {
                    $pair = CodeScanner::splitTopLevel($masked, $s, $e, '=>');
                    [$ks, $ke] = $pair[0];
                    $names[] = $evaluator->evaluateStrict(substr($orig, $ks, $ke - $ks));
                }
            }

            foreach ($names as $name) {
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                $name = trim(preg_replace('/\s+as\s+.*$/i', '', preg_replace('/:.*$/', '', $name) ?? $name) ?? $name);
                if ($isCount) {
                    $counts[] = $name;
                } else {
                    $paths[] = $name;
                }
            }
        }

        return [array_values(array_unique($paths)), array_values(array_unique($counts))];
    }

    /**
     * Whether a relation path (e.g. "users" or "users.governorate") is covered by the eager-load list.
     *
     * @param  list<string>  $eagerLoadPaths
     */
    private function isRelationIncludedInEagerLoad(string $path, array $eagerLoadPaths): bool
    {
        foreach ($eagerLoadPaths as $e) {
            if ($e === $path || str_starts_with($e, $path . '.')) {
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
     * @param  list<string>  $eagerLoadPaths
     * @param  bool  $allowWithoutEagerLoad  When true (e.g. Resource::collection('relation') string), show nested example if controller has no eager loads.
     */
    private function shouldIncludeNestedRelation(string $fullPath, array $eagerLoadPaths, bool $allowWithoutEagerLoad): bool
    {
        if ($eagerLoadPaths === []) {
            return $allowWithoutEagerLoad;
        }

        return $this->isRelationIncludedInEagerLoad($fullPath, $eagerLoadPaths);
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

        $wrap = JsonResource::$wrap;
        if ($wrap !== null && $wrap !== '') {
            return [$wrap => [$itemExample]];
        }

        return [$itemExample];
    }

    /**
     * @param  string  $masked  masked method source
     * @return string none|length|simple|cursor
     */
    private function detectPaginationType(string $masked): string
    {
        if (preg_match('/\bcursorPaginate\s*\(/', $masked)) {
            return 'cursor';
        }
        if (preg_match('/\bsimplePaginate\s*\(/', $masked)) {
            return 'simple';
        }
        if (preg_match('/\bpaginate\s*\(/', $masked)) {
            return 'length';
        }

        return 'none';
    }

    /**
     * Paginated Resource::collection() matches Laravel: data (or custom wrap key), links, meta — with the
     * meta shape of the paginator in use (length-aware, simple, cursor).
     */
    private function buildPaginatedCollectionExample(array $itemExample, string $paginationType): array
    {
        $wrap = JsonResource::$wrap;
        $dataKey = ($wrap !== null && $wrap !== '') ? $wrap : 'data';
        $path = 'http://example.com/api';

        if ($paginationType === 'cursor') {
            $links = ['first' => null, 'last' => null, 'prev' => null, 'next' => null];
            $meta = ['path' => $path, 'per_page' => 15, 'next_cursor' => null, 'prev_cursor' => null];
        } elseif ($paginationType === 'simple') {
            $links = ['first' => $path . '?page=1', 'prev' => null, 'next' => null];
            $meta = ['current_page' => 1, 'from' => 1, 'path' => $path, 'per_page' => 15, 'to' => 1];
        } else {
            $links = ['first' => $path . '?page=1', 'last' => $path . '?page=1', 'prev' => null, 'next' => null];
            $meta = [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 1,
                'links' => [],
                'path' => $path,
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
     * Example of a JsonResource's toArray(). Recursion is bounded by depth and by how often the same
     * resource class already appears among the ancestors (self-referencing resources).
     *
     * @param  list<string>  $eagerLoadPaths  relation paths eager loaded by the controller
     * @param  list<string>  $counts  relation counts loaded by the controller
     * @param  list<string>  $stack  ancestor resource classes
     * @return array<string, mixed>|null
     */
    private function resourceExample(string $resourceClass, array $eagerLoadPaths, array $counts, string $relationPrefix = '', array $stack = [], ?string $modelHint = null): ?array
    {
        if (count($stack) >= self::MAX_RESOURCE_DEPTH) {
            return null;
        }
        $ancestors = count(array_keys($stack, $resourceClass, true));
        if ($ancestors > self::MAX_SAME_RESOURCE_ANCESTORS) {
            return null;
        }

        $key = implode('|', [$resourceClass, $relationPrefix, implode('>', $stack), implode(',', $eagerLoadPaths), implode(',', $counts), (string) $modelHint]);
        if (array_key_exists($key, $this->resourceExamples)) {
            return $this->resourceExamples[$key];
        }
        $this->resourceExamples[$key] = null;

        try {
            $result = $this->buildResourceExample($resourceClass, $resourceClass, $eagerLoadPaths, $counts, $relationPrefix, $stack, $modelHint);
        } catch (Throwable $e) {
            $this->debug('could not infer resource example for ' . $resourceClass, $e);
            $result = null;
        }

        return $this->resourceExamples[$key] = $result;
    }

    /**
     * @param  list<string>  $eagerLoadPaths
     * @param  list<string>  $counts
     * @param  list<string>  $stack
     * @return array<string, mixed>|null
     */
    private function buildResourceExample(string $resourceClass, string $toArrayClass, array $eagerLoadPaths, array $counts, string $relationPrefix, array $stack, ?string $modelHint): ?array
    {
        $model = $this->modelForResource($resourceClass) ?? ($this->models->isModel($modelHint) ? $modelHint : null);

        $method = new ReflectionMethod($toArrayClass, 'toArray');
        $declaring = $method->getDeclaringClass()->getName();
        if ($declaring === JsonResource::class || $declaring === ResourceCollection::class) {
            return $model !== null ? $this->models->modelExample($model) : null;
        }

        $ctx = $this->methodContext($declaring, 'toArray');
        if ($ctx === null) {
            return null;
        }
        $ctx['resource'] = $resourceClass;
        $ctx['model'] = $model;
        $ctx['eager'] = $eagerLoadPaths;
        $ctx['counts'] = $counts;
        $ctx['prefix'] = $relationPrefix;
        $ctx['stack'] = array_merge($stack, [$resourceClass]);

        $returns = $this->returnStatements($ctx);
        if ($returns === []) {
            return null;
        }
        $topLevel = array_values(array_filter($returns, fn (array $r) => $r['depth'] === 1));
        $return = $topLevel !== [] ? end($topLevel) : end($returns);

        return $this->resourceArrayFromExpression($ctx, $return['orig'], $return['masked']);
    }

    /**
     * toArray() return expression: array literal, array_merge(...), parent::toArray($request).
     *
     * @param  array<string, mixed>  $ctx  resource context
     * @return array<string, mixed>|null
     */
    private function resourceArrayFromExpression(array $ctx, string $orig, string $masked): ?array
    {
        $orig = trim($orig);
        $masked = trim($masked);

        if ($masked !== '' && ($masked[0] === '[' || preg_match('/^array\s*\(/i', $masked))) {
            $value = $this->evaluator($ctx, true)->evaluate($orig);

            return is_array($value) ? $this->filterResourceArray($value) : null;
        }

        if (preg_match('/^\\\\?array_merge\s*\(/i', $masked, $mm)) {
            $merged = [];
            foreach (CodeScanner::arguments($orig, $masked, strlen($mm[0]) - 1) as $arg) {
                $merged = array_merge($merged, $this->resourceArrayFromExpression($ctx, $arg['orig'], $arg['masked']) ?? []);
            }

            return $merged === [] ? null : $merged;
        }

        if (preg_match('/^parent\s*::\s*toArray\s*\(/i', $masked)) {
            $parent = get_parent_class($ctx['class']);
            if ($parent !== false && $parent !== JsonResource::class && is_subclass_of($parent, JsonResource::class)) {
                return $this->buildResourceExample($ctx['resource'], $parent, $ctx['eager'], $ctx['counts'], $ctx['prefix'], array_slice($ctx['stack'], 0, -1), $ctx['model']);
            }

            return $ctx['model'] !== null ? $this->models->modelExample($ctx['model']) : null;
        }

        if (preg_match('/^\$this\s*->\s*(?:resource\s*->\s*(?:toArray|attributesToArray)|attributesToArray)\s*\(/', $masked)) {
            return $ctx['model'] !== null ? $this->models->modelExample($ctx['model']) : null;
        }

        return null;
    }

    /**
     * Drops MissingValue entries and splices MergeValue entries, recursively (like JsonResource::filter()).
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function filterResourceArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value instanceof MissingValue) {
                continue;
            }
            if ($value instanceof MergeValue) {
                foreach ($this->filterResourceArray((array) $value->data) as $mergedKey => $mergedValue) {
                    if (is_int($mergedKey)) {
                        $out[] = $mergedValue;
                    } else {
                        $out[$mergedKey] = $mergedValue;
                    }
                }

                continue;
            }
            if (is_array($value)) {
                $value = $this->filterResourceArray($value);
            } elseif ($value instanceof Unresolved) {
                $value = 'string';
            }
            if (is_int($key)) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Example for one value of a resource toArray() array.
     *
     * @param  array<string, mixed>  $ctx  resource context
     */
    private function resourceValue(array $ctx, string $expr, ?string $key): mixed
    {
        $orig = trim($expr);
        $masked = trim(PhpSource::mask($orig));
        if ($masked === '') {
            return $this->guess($key);
        }

        $body = $this->closureBody($orig, $masked);
        if ($body !== null) {
            return $this->resourceExpressionValue($ctx, $body[0], $key);
        }

        $conditional = CodeScanner::splitConditional($masked);
        if ($conditional !== null) {
            $parts = array_map(fn (array $p) => substr($orig, $p[0], $p[1] - $p[0]), $conditional['parts']);
            if ($conditional['type'] === 'ternary') {
                $then = $this->resourceExpressionValue($ctx, $parts[1], $key);

                return $then === null || $then instanceof MissingValue ? $this->resourceExpressionValue($ctx, $parts[2], $key) : $then;
            }
            $left = $this->resourceExpressionValue($ctx, $parts[0], $key);

            return $left === null ? $this->resourceExpressionValue($ctx, $parts[1], $key) : $left;
        }

        if (preg_match('/^\(\s*(int|integer|float|double|real|bool|boolean|string|array|object)\s*\)/i', $masked, $cm)) {
            if (in_array(strtolower($cm[1]), ['string'], true)) {
                $inner = $this->resourceExpressionValue($ctx, substr($orig, strlen($cm[0])), $key);

                return is_string($inner) ? $inner : $this->typedExpressionExample($masked, $key);
            }

            return $this->typedExpressionExample($masked, $key);
        }

        if (preg_match('/^\$this\s*->\s*(when|unless|whenNotNull|whenNull|whenHas|whenAppended|whenLoaded|whenCounted|whenAggregated|whenExistsLoaded|whenPivotLoaded|whenPivotLoadedAs|mergeWhen|mergeUnless|merge)\s*\(/', $masked, $wm)) {
            return $this->conditionalResourceValue($ctx, $wm[1], CodeScanner::arguments($orig, $masked, strlen($wm[0]) - 1), $key);
        }

        if (preg_match(self::RESOURCE_CALL, $masked)) {
            $nested = $this->nestedResourceValue($ctx, $orig, $masked, $key);
            if ($nested !== null) {
                return $nested;
            }
        }

        if (preg_match('/^\$this\s*->\s*(?:resource\s*->\s*)?(\w+)(.*)$/s', $masked, $am) && ! str_starts_with(ltrim($am[2]), '(')) {
            return $this->resourceAttributeValue($ctx, $am[1], trim($am[2]), $key);
        }

        $typed = $this->typedExpressionExample($masked, $key);
        if (! $typed instanceof Unresolved) {
            return $typed;
        }

        return $this->guess($key);
    }

    /**
     * Evaluates a sub-expression of a resource value (literal first, then {@see resourceValue()}).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function resourceExpressionValue(array $ctx, string $orig, ?string $key): mixed
    {
        return $this->evaluator($ctx, true)->evaluate(trim($orig), $key);
    }

    /**
     * Returned expression of {@code fn () => X} / {@code function () { return X; }}.
     *
     * @return array{0: string}|null
     */
    private function closureBody(string $orig, string $masked): ?array
    {
        if (preg_match('/^(?:static\s+)?fn\s*&?\s*\(/', $masked, $fm)) {
            $close = CodeScanner::matchClose($masked, strlen($fm[0]) - 1);
            $arrow = $close === null ? false : strpos($masked, '=>', $close);

            return $arrow === false ? null : [substr($orig, $arrow + 2)];
        }
        if (preg_match('/^(?:static\s+)?function\s*&?\s*\(/', $masked)) {
            if (preg_match('/(?<![\w$>:])return\b/', $masked, $rm, PREG_OFFSET_CAPTURE)) {
                $start = $rm[0][1] + 6;
                $end = CodeScanner::expressionEnd($masked, $start);

                return [substr($orig, $start, $end - $start)];
            }

            return null;
        }

        return null;
    }

    /**
     * $this->when(...), whenLoaded(...), whenCounted(...), mergeWhen(...) and friends.
     *
     * @param  array<string, mixed>  $ctx
     * @param  list<array{orig: string, masked: string, name: ?string, offset: int}>  $args
     */
    private function conditionalResourceValue(array $ctx, string $helper, array $args, ?string $key): mixed
    {
        $literal = fn (int $i): mixed => isset($args[$i]) ? $this->evaluator($ctx, false)->evaluateStrict($args[$i]['orig']) : null;
        $value = fn (int $i): mixed => $this->resourceExpressionValue($ctx, $args[$i]['orig'], $key);

        switch ($helper) {
            case 'when':
            case 'unless':
                return isset($args[1]) ? $value(1) : $this->guess($key);
            case 'whenNotNull':
            case 'whenNull':
                return isset($args[0]) ? $value(0) : $this->guess($key);
            case 'whenHas':
            case 'whenAppended':
                if (isset($args[1])) {
                    return $value(1);
                }
                $attribute = $literal(0);

                return is_string($attribute) ? $this->resourceAttributeValue($ctx, $attribute, '', $key) : $this->guess($key);
            case 'whenLoaded':
                $relation = $literal(0);
                if (! is_string($relation)) {
                    return new MissingValue();
                }
                if (! $this->shouldIncludeNestedRelation($this->buildRelationPath($ctx['prefix'], $relation), $ctx['eager'], false)) {
                    return new MissingValue();
                }
                if (isset($args[1])) {
                    return $value(1);
                }
                $related = $ctx['model'] !== null ? $this->models->relation($ctx['model'], $relation) : null;
                $row = $related !== null ? $this->models->modelExample($related['class']) : null;
                if ($row === null) {
                    return $this->guess($key);
                }

                return $related['many'] ? [$row] : $row;
            case 'whenCounted':
                $relation = $literal(0);
                if (! is_string($relation) || ! in_array($this->buildRelationPath($ctx['prefix'], $relation), $ctx['counts'], true)) {
                    return new MissingValue();
                }

                return isset($args[1]) ? $value(1) : 1;
            case 'whenAggregated':
                return new MissingValue();
            case 'mergeWhen':
            case 'mergeUnless':
            case 'merge':
                $index = $helper === 'merge' ? 0 : 1;
                $merged = isset($args[$index]) ? $this->resourceExpressionValue($ctx, $args[$index]['orig'], null) : null;

                return is_array($merged) ? new MergeValue($merged) : new MissingValue();
            default:
                return new MissingValue();
        }
    }

    /**
     * {@code new XResource(ARG)} / {@code XResource::make(ARG)} / {@code XResource::collection(ARG)} inside toArray().
     * Relations are included only when eager loaded (string relation names are allowed without eager loads).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function nestedResourceValue(array $ctx, string $orig, string $masked, ?string $key): mixed
    {
        if (! preg_match(self::RESOURCE_CALL, $masked, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $className = $m[1][0] !== '' ? $m[1][0] : $m[2][0];
        $fqcn = $this->resolveClass($ctx, $className);
        if ($fqcn === null || ! is_subclass_of($fqcn, JsonResource::class)) {
            return null;
        }
        $isCollection = ($m[3][0] ?? '') === 'collection';
        if (is_subclass_of($fqcn, ResourceCollection::class)) {
            $fqcn = $this->collectedResource($fqcn);
            $isCollection = true;
            if ($fqcn === null) {
                return new MissingValue();
            }
        }

        $args = CodeScanner::arguments($orig, $masked, strlen($m[0][0]) - 1);
        $argMasked = $args[0]['masked'] ?? '';
        $relation = null;
        $allowWithoutEagerLoad = false;
        if (preg_match('/^\$this\s*->\s*whenLoaded\s*\(/', $argMasked, $wm)) {
            $inner = CodeScanner::arguments($args[0]['orig'], $argMasked, strlen($wm[0]) - 1);
            $relation = isset($inner[0]) ? $this->evaluator($ctx, false)->evaluateStrict($inner[0]['orig']) : null;
        } elseif (preg_match('/^\$this\s*->\s*(?:resource\s*->\s*)?(\w+)$/', $argMasked, $pm)) {
            $relation = $pm[1];
        } elseif ($argMasked !== '' && ($argMasked[0] === '"' || $argMasked[0] === "'")) {
            $relation = $this->evaluator($ctx, false)->evaluateStrict($args[0]['orig']);
            $allowWithoutEagerLoad = true;
        } else {
            $allowWithoutEagerLoad = true;
        }

        $path = $ctx['prefix'];
        $relatedModel = null;
        if (is_string($relation) && $relation !== '') {
            $path = $this->buildRelationPath($ctx['prefix'], $relation);
            if (! $this->shouldIncludeNestedRelation($path, $ctx['eager'], $allowWithoutEagerLoad)) {
                return new MissingValue();
            }
            $relatedModel = $ctx['model'] !== null ? ($this->models->relation($ctx['model'], $relation)['class'] ?? null) : null;
        }

        $example = $this->resourceExample($fqcn, $ctx['eager'], $ctx['counts'], $path, $ctx['stack'], $relatedModel);
        if ($example === null) {
            return new MissingValue();
        }

        return $isCollection ? [$example] : $example;
    }

    /**
     * {@code $this->attr}, {@code $this->resource->attr}, {@code $this->attr->value}, {@code $this->date?->format(...)}.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function resourceAttributeValue(array $ctx, string $attribute, string $rest, ?string $key): mixed
    {
        if ($rest === '' || preg_match('/^\??->\s*value$/', $rest)) {
            if ($ctx['model'] !== null) {
                $value = $this->models->attributeExample($ctx['model'], $attribute);
                if (! $value instanceof Unresolved) {
                    return $value;
                }
            }
            $guess = ExampleGuesser::property($attribute);
            if ($guess === 'string' && $key !== null && $key !== $attribute) {
                $guess = ExampleGuesser::property($key);
            }

            return $guess;
        }

        $typed = $this->typedExpressionExample('$this->' . $attribute . $rest, $key);
        if (! $typed instanceof Unresolved) {
            return $typed;
        }
        if (preg_match('/^\??->\s*diffForHumans\s*\(/', $rest)) {
            return '1 hour ago';
        }
        if (preg_match('/^\??->\s*timestamp$/', $rest)) {
            return 1704110400;
        }
        if (preg_match('/^\??->\s*(\w+)$/', $rest, $pm)) {
            return $this->guess($key ?? $pm[1]);
        }

        return $this->guess($key ?? $attribute);
    }

    /**
     * Model behind a resource: {@code @mixin} docblock, else App\Models\{Name without "Resource"}.
     */
    private function modelForResource(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->resourceModels)) {
            return $this->resourceModels[$resourceClass];
        }

        $model = null;
        try {
            $ref = new ReflectionClass($resourceClass);
            $doc = $ref->getDocComment();
            $file = $ref->getFileName();
            if ($doc !== false && $file !== false && preg_match('/@mixin\s+(\\\\?[\w\\\\]+)/', $doc, $dm)) {
                $candidate = $this->source->resolveClass($dm[1], $file);
                if ($this->models->isModel($candidate)) {
                    $model = $candidate;
                }
            }
            if ($model === null) {
                $base = preg_replace('/Resource$/', '', $ref->getShortName());
                $namespaces = array_unique(['App\\Models\\', rtrim(app()->getNamespace(), '\\') . '\\Models\\']);
                foreach ($namespaces as $namespace) {
                    if ($base !== '' && $this->models->isModel($namespace . $base)) {
                        $model = $namespace . $base;

                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->debug('could not resolve model for ' . $resourceClass, $e);
        }

        return $this->resourceModels[$resourceClass] = $model;
    }

    /**
     * abort(CODE[, msg]), abort_if(cond, CODE[, msg]), abort_unless(cond, CODE[, msg]).
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    private function abortExamples(array $ctx): array
    {
        if (! preg_match_all('/(?<![\w$>:\\\\])(abort|abort_if|abort_unless)\s*\(/', $ctx['masked'], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $ctx['bodyStart'])) {
            return [];
        }

        $out = [];
        foreach ($m as $match) {
            $args = CodeScanner::arguments($ctx['body'], $ctx['masked'], $match[0][1] + strlen($match[0][0]) - 1);
            $codeIndex = $match[1][0] === 'abort' ? 0 : 1;
            $codeArg = CodeScanner::argument($args, $codeIndex, 'code');
            if ($codeArg === null) {
                continue;
            }
            $status = $this->resolveStatusExpression($ctx, $codeArg['orig'], $codeArg['masked'], $match[0][1]);
            if ($status === null || $status < 400) {
                continue;
            }
            $messageArg = CodeScanner::argument($args, $codeIndex + 1, 'message');
            $out[] = [$status, ['message' => $this->messageFromArgument($ctx, $messageArg, $status)]];
        }

        return $out;
    }

    /**
     * throw new HttpException(CODE, 'msg') / NotFoundHttpException('msg') / AccessDeniedHttpException / ModelNotFoundException ...
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    private function thrownExceptionExamples(array $ctx): array
    {
        if (! preg_match_all('/\bthrow\s+new\s+(\\\\?[A-Za-z_][\w\\\\]*)\s*\(/', $ctx['masked'], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $ctx['bodyStart'])) {
            return [];
        }

        $known = [
            'Symfony\Component\HttpKernel\Exception\HttpException' => [null, 1],
            'Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException' => [401, 1],
            'Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException' => [429, 1],
            'Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException' => [503, 1],
            'Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException' => [405, 1],
            'Illuminate\Database\Eloquent\ModelNotFoundException' => [404, -1],
            'Illuminate\Database\RecordsNotFoundException' => [404, -1],
            'Illuminate\Auth\Access\AuthorizationException' => [403, 0],
            'Illuminate\Auth\AuthenticationException' => [401, 0],
        ];
        $defaultMessages = [
            'Illuminate\Auth\Access\AuthorizationException' => 'This action is unauthorized.',
            'Illuminate\Auth\AuthenticationException' => 'Unauthenticated.',
        ];

        $out = [];
        foreach ($m as $match) {
            $class = $this->resolveClass($ctx, $match[1][0]);
            if ($class === null) {
                continue;
            }
            $args = CodeScanner::arguments($ctx['body'], $ctx['masked'], $match[0][1] + strlen($match[0][0]) - 1);
            $spec = null;
            foreach ($known as $knownClass => $knownSpec) {
                if ($class === $knownClass || (class_exists($knownClass) && is_subclass_of($class, $knownClass) && $knownSpec[0] !== null)) {
                    $spec = $knownSpec;

                    break;
                }
            }
            if ($spec === null && is_subclass_of($class, HttpExceptionInterface::class)) {
                $spec = [$this->httpExceptionStatus($class), 0];
            }
            if ($spec === null) {
                continue;
            }

            [$status, $messageIndex] = $spec;
            if ($status === null) {
                $codeArg = CodeScanner::argument($args, 0, 'statusCode');
                $status = $codeArg === null ? null : $this->resolveStatusExpression($ctx, $codeArg['orig'], $codeArg['masked'], $match[0][1]);
            }
            if ($status === null || $status < 400) {
                continue;
            }
            $message = $messageIndex < 0
                ? $this->genericMessage($status)
                : $this->messageFromArgument($ctx, CodeScanner::argument($args, $messageIndex, 'message'), $status, $defaultMessages[$class] ?? null);
            $out[] = [$status, ['message' => $message]];
        }

        return $out;
    }

    private function httpExceptionStatus(string $class): ?int
    {
        try {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || $ref->getConstructor()?->getNumberOfRequiredParameters() > 0) {
                return null;
            }
            $exception = $ref->newInstance();

            return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : null;
        } catch (Throwable $e) {
            $this->debug('could not determine status of ' . $class, $e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array{orig: string, masked: string}|null  $arg
     */
    private function messageFromArgument(array $ctx, ?array $arg, int $status, ?string $default = null): string
    {
        if ($arg !== null) {
            $message = $this->evaluator($ctx, false)->evaluateStrict($arg['orig']);
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return $default ?? $this->genericMessage($status);
    }

    private function genericMessage(int $status): string
    {
        $text = SymfonyResponse::$statusTexts[$status] ?? null;

        return $text !== null ? $text . '.' : 'An error occurred.';
    }

    private function debug(string $message, Throwable $e): void
    {
        try {
            if (config('app.debug')) {
                logger()->debug('g4t/swagger: ' . $message, [
                    'action' => $this->currentAction,
                    'exception' => $e,
                ]);
            }
        } catch (Throwable) {
            // Logging must never break documentation generation.
        }
    }
}
