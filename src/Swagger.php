<?php

namespace G4T\Swagger;

use G4T\Swagger\Attributes\SwaggerSection;
use G4T\Swagger\Extensions\Extension;
use G4T\Swagger\Extensions\Extensions;
use G4T\Swagger\Extensions\RouteInfo;
use G4T\Swagger\Sections\Paths;
use G4T\Swagger\Sections\Schemas;
use G4T\Swagger\Sections\Tags;
use G4T\Swagger\Support\Debug;
use G4T\Swagger\Support\SchemaBuilder;
use G4T\Swagger\Support\Settings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use stdClass;
use Throwable;

class Swagger
{
    use Helpers, Paths, Schemas, Tags;

    /**
     * Generate the OpenAPI document.
     *
     * @return array<string, mixed>
     */
    /**
     * Registers an extension (usually from a service provider's boot()).
     *
     * @param  Extension|class-string<Extension>  $extension
     */
    public static function extend(Extension|string $extension): void
    {
        Extensions::add($extension);
    }

    public function swagger(): array
    {
        $generated = $this->generateSwaggerJsonResponse();

        return Extensions::document([
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('swagger.title'),
                'description' => config('swagger.description'),
                'termsOfService' => 'http://swagger.io/terms/',
                'contact' => [
                    'email' => config('swagger.email'),
                ],
                'license' => [
                    'name' => 'Github',
                    'url' => 'https://github.com/hussein4alaa',
                ],
                'version' => config('swagger.version'),
            ] + $this->infoTranslations(),
            'servers' => config('swagger.servers'),
            'tags' => $generated->tags,
            'paths' => $generated->paths === [] ? new stdClass : $generated->paths,
            'components' => [
                'schemas' => $generated->schemas === [] ? new stdClass : $generated->schemas,
                'securitySchemes' => empty($generated->securitySchemes) ? new stdClass : $generated->securitySchemes,
            ],
        ]);
    }

    /**
     * Collect every documented route in a single pass and build tags, paths and request schemas.
     *
     * @return object{tags: list<array<string, string>>, paths: array<string, mixed>, schemas: array<string, mixed>, securitySchemes: mixed}
     */
    public function generateSwaggerJsonResponse(): object
    {
        $inferExamples = (bool) config('swagger.infer_response_examples', true);
        $inferErrorExamples = (bool) config('swagger.infer_error_response_examples', true);
        $exampleExtractor = ($inferExamples || $inferErrorExamples) ? new ResponseExampleExtractor : null;

        $allRoutes = $this->collectDocumentedRoutes(null);
        $version = $this->getVersion();
        $routes = $version === null
            ? $allRoutes
            : array_values(array_filter($allRoutes, fn (RoutingRoute $route) => $this->uriMatchesVersion('/'.ltrim($route->uri(), '/'), $version)));

        // Names are resolved across every version so a tag/schema keeps the same name in each version view.
        [$tagNames, $schemaPrefixes] = $this->resolveControllerNames(array_map(
            fn (RoutingRoute $route) => $this->getControllerPath($route->getAction('controller')),
            $allRoutes,
        ));

        $responseSchemas = $inferExamples && $exampleExtractor !== null && config('swagger.response_schemas', true);
        if ($responseSchemas) {
            // Request body schemas own their names; response components must pick others.
            $requestSchemaNames = [];
            foreach ($allRoutes as $route) {
                $name = ($schemaPrefixes[$this->getControllerPath($route->getAction('controller'))] ?? '').$this->schemaName(ltrim($route->getActionName(), '\\'));
                array_push($requestSchemaNames, $name, $name.'Form');
            }
            $exampleExtractor->reserveSchemaNames($requestSchemaNames);
        }

        $mappingPrefix = config('swagger.mapping_prefix') ?? [];
        $apiRoutes = [];
        $schemas = [];
        $controllers = [];

        foreach ($routes as $route) {
            $action = ltrim($route->getActionName(), '\\');
            // Invokable controllers / action classes: read their __invoke like any other action.
            if (! str_contains($action, '@') && method_exists($action, '__invoke')) {
                $action .= '@__invoke';
            }
            $controllerClass = $this->getControllerPath($route->getAction('controller'));
            $controllerShort = $this->getControllerName($route->getAction('controller'));
            $tag = $tagNames[$controllerClass] ?? $controllerShort;
            $prefix = $route->getPrefix();
            $uri = '/'.ltrim($route->uri(), '/');

            if (is_array($mappingPrefix) && $prefix !== null && isset($mappingPrefix[$prefix])) {
                $uri = str_replace($prefix, $mappingPrefix[$prefix], $uri);
                $prefix = $mappingPrefix[$prefix];
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $method = $methods[0] ?? 'GET';
            $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

            // Extensions describe input the FormRequest rules do not (Data objects, actions…); first answer wins.
            $routeInfo = Extensions::any()
                ? new RouteInfo($route, $method, $uri, $controllerClass, explode('@', $action, 2)[1] ?? null, $this->generateOperationId($uri, $method), $tag)
                : null;
            $requestSpec = $routeInfo !== null ? Extensions::request($routeInfo) : null;
            $inQuery = $requestSpec !== null && $requestSpec->query;

            $validations = $requestSpec !== null ? ($requestSpec->rules ?? []) : $this->getRequestClassName($action);
            $builder = new SchemaBuilder($validations);
            $schemaName = ($schemaPrefixes[$controllerClass] ?? '').$this->schemaName($action);

            $documentedMethod = $method;
            $requestBody = null;
            if ($hasBody && ! $inQuery && $requestSpec?->schema !== null) {
                $schemas[$schemaName] = $requestSpec->schema;
                $requestBody = ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$schemaName}"]]], 'required' => true]
                    + array_filter(['description' => $route->action['description'] ?? '']);
            } elseif ($hasBody && ! $inQuery && ! $builder->isEmpty()) {
                $extra = [];
                if ($builder->hasFiles() && $method !== 'POST') {
                    // PHP only parses multipart bodies on POST: Laravel needs method spoofing.
                    $documentedMethod = 'POST';
                    $extra['_method'] = ['type' => 'string', 'enum' => [$method], 'default' => $method];
                }
                $requestBody = $this->buildRequestBody($builder, $schemaName, $extra, $schemas, $route->action['description'] ?? '');
            }

            $controllers[$tag] ??= [
                'name' => $tag,
                'class' => $controllerClass,
                'description' => $this->getSectionAttributeValue($controllerClass),
            ];

            $params = $hasBody && ! $inQuery ? [] : $builder->queryParameters();
            $this->spatieSchema($params, $method);

            $apiRoutes[] = [
                'prefix' => $prefix,
                'method' => $documentedMethod,
                'original_method' => $method,
                'methods' => $methods,
                'controller' => $tag,
                'storage_controller' => $controllerShort,
                'uri' => $uri,
                'description' => $route->action['description'] ?? '',
                'summary' => $route->action['summary'] ?? null,
                'status' => (array) ($route->action['g4t_status'] ?? []),
                'hidden_in_code' => ! empty($route->action['is_hidden']),
                'name' => $this->getRouteName($route->uri(), $prefix),
                'schema_name' => $schemaName,
                'action' => $action,
                'middleware' => $route->middleware(),
                'validations' => $validations,
                'query_params' => $params,
                'path_params' => $this->pathParameterSchemas($route, $action),
                'params' => $params,
                'operation_id' => $this->generateOperationId($uri, $documentedMethod),
                'has_schema' => $requestBody !== null,
                'request_body' => $requestBody,
                'need_token' => $this->checkIfTokenIsRequired($route),
                'success_status' => $this->inferSuccessStatus($exampleExtractor, $action, $method),
                'error_response_examples' => $inferErrorExamples && $exampleExtractor ? ($this->safely(fn () => $exampleExtractor->extractErrorResponseExamples($action), $action) ?? []) : [],
                'route_info' => $routeInfo,
            ] + $this->successResponse($exampleExtractor, $action, $inferExamples, $responseSchemas);
        }

        [$apiRoutes, $controllers] = $this->applyLayout($apiRoutes, $controllers);

        $swaggerJson = new stdClass;
        $swaggerJson->tags = $this->getTags($controllers);
        $swaggerJson->paths = $this->formatPaths($apiRoutes);
        $swaggerJson->schemas = $responseSchemas ? $schemas + $exampleExtractor->responseComponents() : $schemas;
        $swaggerJson->securitySchemes = config('swagger.security_schemes');

        return $swaggerJson;
    }

    /**
     * Keep operations hidden from the dashboard in the document (the dashboard lists them all).
     */
    /**
     * The document without the dashboard's text changes (the dashboard shows the generated texts).
     */
    public function withoutTextChanges(bool $without = true): static
    {
        $this->rawTexts = $without;

        return $this;
    }

    /**
     * English replaces the title / description; other languages travel as x-translations.
     *
     * @return array<string, mixed>
     */
    private function infoTranslations(): array
    {
        $info = $this->rawTexts ? [] : Settings::layout()['texts']['info'];
        $translations = array_diff_key($info, ['en' => true]);

        return array_filter([
            'title' => $info['en']['title'] ?? null,
            'description' => $info['en']['description'] ?? null,
            'x-translations' => $translations === [] ? null : $translations,
        ], fn ($value) => $value !== null);
    }

    public function includingHidden(bool $include = true): static
    {
        $this->includeHidden = $include;

        return $this;
    }

    /** @var bool */
    private $includeHidden = false;

    private $rawTexts = false;

    /**
     * Dashboard layout: drop hidden operations and order tags and operations (unlisted ones keep
     * their place after the listed ones, in route order).
     *
     * @param  list<array<string, mixed>>  $apiRoutes
     * @param  array<string, array<string, mixed>>  $controllers
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function applyLayout(array $apiRoutes, array $controllers): array
    {
        $layout = Settings::layout();
        if (! $this->includeHidden && ($layout['hidden'] !== [] || $layout['hidden_tags'] !== [])) {
            $hidden = array_flip($layout['hidden']);
            $hiddenTags = array_flip($layout['hidden_tags']);
            $apiRoutes = array_values(array_filter($apiRoutes, fn (array $route) => ! isset($hidden[$route['operation_id']]) && ! isset($hiddenTags[$route['controller']])));
            $used = array_flip(array_map(fn (array $route) => $route['controller'], $apiRoutes));
            $controllers = array_filter($controllers, fn (array $controller, $tag) => isset($used[$tag]), ARRAY_FILTER_USE_BOTH);
        }

        if (! $this->rawTexts) {
            [$apiRoutes, $controllers] = $this->applyTexts($apiRoutes, $controllers, $layout['texts']);
            foreach ($apiRoutes as $index => $route) {
                // Dashboard status / note replace the ones set in code with ->deprecated() / ->beta().
                $meta = $layout['meta']['operations'][$route['operation_id']] ?? null;
                if ($meta === null) {
                    continue;
                }
                $status = array_replace($route['status'], $meta);
                if (($status['status'] ?? null) !== 'deprecated') {
                    unset($status['sunset'], $status['replacement']);
                }
                if (($status['status'] ?? null) === 'stable') {
                    unset($status['status']);
                }
                $apiRoutes[$index]['status'] = $status;
            }
        }

        $tagRank = array_flip($layout['tags']);
        $tagNames = array_keys($controllers);
        usort($tagNames, function ($a, $b) use ($tagRank, $tagNames) {
            $rankA = $tagRank[$a] ?? PHP_INT_MAX;
            $rankB = $tagRank[$b] ?? PHP_INT_MAX;

            return $rankA === $rankB ? array_search($a, $tagNames, true) <=> array_search($b, $tagNames, true) : $rankA <=> $rankB;
        });
        $ordered = [];
        foreach ($tagNames as $name) {
            $ordered[$name] = $controllers[$name];
        }

        $position = array_flip($tagNames);
        foreach ($apiRoutes as $index => $route) {
            $apiRoutes[$index]['_order'] = [
                $position[$route['controller']] ?? PHP_INT_MAX,
                array_flip($layout['operations'][$route['controller']] ?? [])[$route['operation_id']] ?? PHP_INT_MAX,
                $index,
            ];
        }
        usort($apiRoutes, fn (array $a, array $b) => $a['_order'] <=> $b['_order']);
        foreach ($apiRoutes as $index => $route) {
            unset($apiRoutes[$index]['_order']);
            // OpenAPI groups operations by path, so the exact order travels as x-order.
            $apiRoutes[$index]['x_order'] = $index + 1;
        }

        return [$apiRoutes, $ordered];
    }

    /**
     * Dashboard texts: English replaces the generated summary / description, other languages are added as
     * x-translations for the g4t UI's language switch.
     *
     * @param  array<string, mixed>  $texts
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function applyTexts(array $apiRoutes, array $controllers, array $texts): array
    {
        foreach ($apiRoutes as $index => $route) {
            $languages = $texts['operations'][$route['operation_id']] ?? [];
            if (isset($languages['en']['summary'])) {
                $apiRoutes[$index]['summary'] = $languages['en']['summary'];
            }
            if (isset($languages['en']['description'])) {
                $apiRoutes[$index]['description'] = $languages['en']['description'];
            }
            unset($languages['en']);
            if ($languages !== []) {
                $apiRoutes[$index]['x_translations'] = $languages;
            }
        }

        foreach ($controllers as $tag => $controller) {
            $languages = $texts['tags'][$tag] ?? [];
            if (isset($languages['en'])) {
                $controllers[$tag]['description'] = $languages['en'];
            }
            unset($languages['en']);
            if ($languages !== []) {
                $controllers[$tag]['x_translations'] = array_map(fn (string $description) => ['description' => $description], $languages);
            }
        }

        return [$apiRoutes, $controllers];
    }

    /**
     * @return list<RoutingRoute>
     */
    private function collectDocumentedRoutes(?string $version): array
    {
        $showPrefixes = config('swagger.show_prefix');
        $includeWebRoutes = (bool) config('swagger.include_web_routes');
        $documented = [];

        foreach (Route::getRoutes() as $route) {
            $isDocumentable = $includeWebRoutes
                ? str_contains($route->getActionName(), 'App\\Http\\Controllers')
                : $this->isApiRoute($route);

            if (! $isDocumentable || ! is_string($route->getAction('controller'))) {
                continue;
            }
            // ->hiddenDoc() routes only reach the dashboard and partner links that pick them (includingHidden()).
            if (ltrim($route->getActionName(), '\\') === 'Closure' || (! empty($route->action['is_hidden']) && ! $this->includeHidden)) {
                continue;
            }
            if ($version !== null && ! $this->uriMatchesVersion('/'.ltrim($route->uri(), '/'), $version)) {
                continue;
            }

            $prefix = $route->getPrefix();
            $allowedPrefixes = is_array($showPrefixes) && count($showPrefixes) > 0 ? $showPrefixes : [$prefix];
            if (! in_array($prefix, $allowedPrefixes, true)) {
                continue;
            }

            $documented[] = $route;
        }

        return $documented;
    }

    /**
     * Tag names and schema-name prefixes per controller class. Controllers that share a short name
     * (e.g. Api\V1\ProductController and Api\V2\ProductController) get distinguishing namespace segments.
     *
     * @param  list<string|null>  $classes
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function resolveControllerNames(array $classes): array
    {
        $byShortName = [];
        foreach (array_unique(array_filter($classes)) as $class) {
            $byShortName[$this->getControllerName($class)][] = $class;
        }

        $tags = [];
        $prefixes = [];
        foreach ($byShortName as $short => $group) {
            if (count($group) === 1) {
                $tags[$group[0]] = $short;
                $prefixes[$group[0]] = '';

                continue;
            }

            $namespaces = array_map(fn (string $class) => array_slice(explode('\\', $class), 0, -1), $group);
            $depth = 1;
            $maxDepth = max(array_map('count', $namespaces));
            while ($depth < $maxDepth) {
                $suffixes = array_map(fn (array $segments) => implode('/', array_slice($segments, -$depth)), $namespaces);
                if (count(array_unique($suffixes)) === count($suffixes)) {
                    break;
                }
                $depth++;
            }

            foreach ($group as $index => $class) {
                $segments = array_slice($namespaces[$index], -$depth);
                $tags[$class] = implode('/', $segments).'/'.$short;
                $prefixes[$class] = Str::studly(implode('_', $segments));
            }
        }

        return [$tags, $prefixes];
    }

    /**
     * Inferred success example and, when response schemas are on, its schema ($ref to resource/model components).
     *
     * @return array{response_example: mixed, response_schema: array<string, mixed>|null}
     */
    private function successResponse(?ResponseExampleExtractor $extractor, string $action, bool $inferExamples, bool $withSchema): array
    {
        if (! $inferExamples || $extractor === null) {
            return ['response_example' => null, 'response_schema' => null];
        }

        if ($withSchema) {
            $inferred = $this->safely(fn () => $extractor->extractWithSchema($action), $action);

            return ['response_example' => $inferred['example'] ?? null, 'response_schema' => $inferred['schema'] ?? null];
        }

        return ['response_example' => $this->safely(fn () => $extractor->extract($action), $action), 'response_schema' => null];
    }

    /**
     * @param  array<string, array<string, mixed>>  $extra
     * @param  array<string, mixed>  $schemas
     * @return array<string, mixed>
     */
    private function buildRequestBody(SchemaBuilder $builder, string $schemaName, array $extra, array &$schemas, string $description): array
    {
        if ($builder->hasFiles()) {
            $schemas[$schemaName] = $builder->formSchema($extra);
            $content = [
                'multipart/form-data' => ['schema' => ['$ref' => "#/components/schemas/{$schemaName}"]],
            ];
        } else {
            $schemas[$schemaName] = $builder->jsonSchema($extra);
            $schemas[$schemaName.'Form'] = $builder->formSchema($extra);
            $content = [
                'application/json' => ['schema' => ['$ref' => "#/components/schemas/{$schemaName}"]],
                'multipart/form-data' => ['schema' => ['$ref' => "#/components/schemas/{$schemaName}Form"]],
            ];
        }

        $body = ['content' => $content, 'required' => true];
        if ($description !== '') {
            $body['description'] = $description;
        }

        return $body;
    }

    private function inferSuccessStatus(?ResponseExampleExtractor $extractor, string $action, string $method): int
    {
        if ($extractor !== null && method_exists($extractor, 'inferSuccessStatus')) {
            $status = $this->safely(fn () => $extractor->inferSuccessStatus($action), $action);
            if (is_int($status) && $status >= 200 && $status < 300) {
                return $status;
            }
        }

        return 200;
    }

    private function safely(callable $callback, string $action): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Debug::log("example inference failed for {$action}", $e);

            return null;
        }
    }

    public function getControllerPath($controller)
    {
        if (! is_string($controller)) {
            return null;
        }

        return explode('@', $controller)[0];
    }

    private function getSectionAttributeValue(?string $controllerClassName)
    {
        if ($controllerClassName === null || ! class_exists($controllerClassName)) {
            return null;
        }

        try {
            $attributes = (new ReflectionClass($controllerClassName))->getAttributes(SwaggerSection::class);

            return $attributes === [] ? null : $attributes[0]->newInstance()->getValue();
        } catch (Throwable $e) {
            Debug::log("could not read #[SwaggerSection] on {$controllerClassName}", $e);

            return null;
        }
    }

    private function getVersion()
    {
        $versions = config('swagger.versions');
        $version = 'api/';
        if (request()->filled('version') && in_array(request()->version, $versions)) {
            if (request()->version == 'all') {
                $version = null;
            } else {
                $version = 'api/'.request()->version;
            }
        }

        return $version;
    }

    /**
     * `api/v1` must match `/api/v1/...` but not `/api/v10/...`.
     */
    private function uriMatchesVersion(string $uri, string $version): bool
    {
        $version = trim($version, '/');

        return (bool) preg_match('#(^|/)'.preg_quote($version, '#').'(/|$)#', $uri);
    }

    private function spatieSchema(&$params, $method)
    {
        if ($method !== 'GET' || ! config('swagger.spatie_query_builder')) {
            return;
        }

        $params[] = [
            'name' => 'filter',
            'in' => 'query',
            'description' => 'Filter',
            'required' => false,
            'style' => 'deepObject',
            'explode' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => [
                    'type' => 'string',
                    'description' => 'The dynamic filtering',
                ],
            ],
        ];
        foreach (['sort', 'include'] as $name) {
            $params[] = [
                'name' => $name,
                'in' => 'query',
                'description' => $name,
                'required' => false,
                'schema' => ['type' => 'string', 'nullable' => true],
            ];
        }
    }
}
