<?php

namespace G4T\Swagger;

use G4T\Swagger\Attributes\SwaggerSection;
use G4T\Swagger\Sections\Paths;
use G4T\Swagger\Sections\Schemas;
use G4T\Swagger\Sections\Tags;
use G4T\Swagger\Support\Debug;
use G4T\Swagger\Support\SchemaBuilder;
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
    public function swagger(): array
    {
        $generated = $this->generateSwaggerJsonResponse();

        return [
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
            ],
            'servers' => config('swagger.servers'),
            'tags' => $generated->tags,
            'paths' => $generated->paths === [] ? new stdClass : $generated->paths,
            'components' => [
                'schemas' => $generated->schemas === [] ? new stdClass : $generated->schemas,
                'securitySchemes' => empty($generated->securitySchemes) ? new stdClass : $generated->securitySchemes,
            ],
        ];
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

        $mappingPrefix = config('swagger.mapping_prefix') ?? [];
        $apiRoutes = [];
        $schemas = [];
        $controllers = [];

        foreach ($routes as $route) {
            $action = ltrim($route->getActionName(), '\\');
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

            $validations = $this->getRequestClassName($action);
            $builder = new SchemaBuilder($validations);
            $schemaName = ($schemaPrefixes[$controllerClass] ?? '').$this->schemaName($action);

            $documentedMethod = $method;
            $requestBody = null;
            if ($hasBody && ! $builder->isEmpty()) {
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

            $params = $hasBody ? [] : $builder->queryParameters();
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
                'response_example' => $inferExamples && $exampleExtractor ? $this->safely(fn () => $exampleExtractor->extract($action), $action) : null,
                'error_response_examples' => $inferErrorExamples && $exampleExtractor ? ($this->safely(fn () => $exampleExtractor->extractErrorResponseExamples($action), $action) ?? []) : [],
            ];
        }

        $swaggerJson = new stdClass;
        $swaggerJson->tags = $this->getTags($controllers);
        $swaggerJson->paths = $this->formatPaths($apiRoutes);
        $swaggerJson->schemas = $schemas;
        $swaggerJson->securitySchemes = config('swagger.security_schemes');

        return $swaggerJson;
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
            if (ltrim($route->getActionName(), '\\') === 'Closure' || ! empty($route->action['is_hidden'])) {
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
