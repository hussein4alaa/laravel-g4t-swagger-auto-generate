<?php

namespace G4T\Swagger;

use G4T\Swagger\Support\Debug;
use G4T\Swagger\Support\SchemaBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

trait Helpers
{
    public function getControllerName(string $action): string
    {
        $segments = explode('\\', $action);
        $controller = end($segments);

        if (strpos($controller, '@') !== false) {
            $controller = explode('@', $controller)[0];
        }

        return str_replace(['Controller', 'controller'], '', $controller);
    }

    public function isApiRoute(object $route): bool
    {
        return in_array('api', $route->middleware());
    }

    public function getRouteName(string $route, $prefix = null): string
    {
        if (! $prefix) {
            return 'unknown';
        }
        $escapedPrefix = preg_quote($prefix, '/');
        if (preg_match("/{$escapedPrefix}\/([^\/]+)/", $route, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * A stable, unique, identifier-safe operationId, e.g. `put_api_v1_categories_category`.
     */
    public function generateOperationId(string $uri, string $method): string
    {
        $method = strtolower(explode('|', $method)[0]);
        $path = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $uri), '_');

        return $method.'_'.$path;
    }

    /**
     * Rules of the FormRequest type-hinted on the controller action.
     *
     * The request is created through the container (without running validation or authorization)
     * so rules() may use injected services, `$this->route()` or `$this->user()` safely.
     *
     * @return array<string, mixed>
     */
    public function getRequestClassName(string $controllerMethod): array
    {
        if ($controllerMethod === 'Closure' || ! str_contains($controllerMethod, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $controllerMethod, 2);

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable $e) {
            Debug::log("cannot reflect {$controllerMethod}", $e);

            return [];
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $requestClass = $type->getName();
            if (! class_exists($requestClass) || ! method_exists($requestClass, 'rules')) {
                continue;
            }

            try {
                return $this->rulesOf($requestClass);
            } catch (Throwable $e) {
                Debug::log("rules() of {$requestClass} failed", $e);

                return [];
            }
        }

        return [];
    }

    /**
     * @param  class-string  $requestClass
     * @return array<string, mixed>
     */
    private function rulesOf(string $requestClass): array
    {
        if (is_subclass_of($requestClass, Request::class)) {
            /** @var Request $request */
            $request = $requestClass::createFrom(app('request'), new $requestClass);
            $request->setRouteResolver(fn () => null);
            if ($request instanceof FormRequest) {
                $request->setContainer(app())->setRedirector(app('redirect'));
            }
        } else {
            $request = app()->make($requestClass);
        }

        $rules = app()->call([$request, 'rules']);

        return is_array($rules) ? $rules : [];
    }

    public function schemaName(string $action): string
    {
        $className = class_basename($action);
        $className = str_replace('Controller', '', $className);
        $className = strstr($className, '@', true) ?: $className;
        $methodName = Str::afterLast($action, '@');

        return Str::studly(ucfirst($className).ucfirst($methodName));
    }

    /**
     * Bracket notation for a dot key (`address.city` → `address[city]`, `tags.*` → `tags[]`).
     */
    public function getInputName(string $string, int $number = 0): string
    {
        $name = str_replace('.*', '[]', $string);

        return preg_replace('/\.([^.\[\]]+)/', '[$1]', $name);
    }

    /**
     * Query parameters for the given rules plus the route's path parameters.
     *
     * @return list<array<string, mixed>>
     */
    public function formatParams($validations, $route)
    {
        $methods = array_diff($route->methods(), ['HEAD']);
        $params = in_array(reset($methods), ['POST', 'PUT', 'PATCH'], true)
            ? []
            : (new SchemaBuilder(is_array($validations) ? $validations : []))->queryParameters();

        foreach ($this->pathParameterSchemas($route, (string) $route->getActionName()) as $name => $schema) {
            $params[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ];
        }

        return $params;
    }

    /**
     * Schema of each path parameter, from route constraints (whereNumber, whereUuid, ...) or the
     * controller signature (int / bound model key type).
     *
     * @return array<string, array<string, mixed>>
     */
    public function pathParameterSchemas(RoutingRoute $route, string $action): array
    {
        $controllerParameters = [];
        if (str_contains($action, '@')) {
            try {
                foreach ((new ReflectionMethod(...explode('@', ltrim($action, '\\'), 2)))->getParameters() as $parameter) {
                    $controllerParameters[$parameter->getName()] = $parameter->getType();
                }
            } catch (Throwable) {
            }
        }

        $schemas = [];
        foreach ($route->parameterNames() as $name) {
            $schemas[$name] = $this->pathParameterSchema($route, $name, $controllerParameters[$name] ?? null);
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    private function pathParameterSchema(RoutingRoute $route, string $name, mixed $type): array
    {
        $pattern = $route->wheres[$name] ?? null;
        if (is_string($pattern)) {
            if (in_array($pattern, ['[0-9]+', '\d+', '[0-9]*'], true)) {
                return ['type' => 'integer'];
            }
            if (str_starts_with($pattern, '[\da-fA-F]{8}-')) {
                return ['type' => 'string', 'format' => 'uuid'];
            }

            return ['type' => 'string', 'pattern' => '^'.$pattern.'$'];
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return ['type' => match ($type->getName()) {
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    default => 'string',
                }];
            }

            $class = $type->getName();
            if (is_subclass_of($class, Model::class)) {
                try {
                    $model = new $class;
                    $field = $route->bindingFieldFor($name) ?? $model->getRouteKeyName();
                    $isIntegerKey = $field === $model->getKeyName() && in_array($model->getKeyType(), ['int', 'integer'], true);

                    return [
                        'type' => $isIntegerKey ? 'integer' : 'string',
                        'description' => class_basename($class).' '.$field,
                    ];
                } catch (Throwable $e) {
                    Debug::log("cannot inspect bound model {$class}", $e);
                }
            }
        }

        return ['type' => 'string'];
    }

    public function pathRequired($route)
    {
        return true;
    }

    /**
     * True when the route uses one of the configured auth middlewares (`auth`, `auth:sanctum`, ...).
     */
    public function checkIfTokenIsRequired($route)
    {
        $authMiddlewares = config('swagger.auth_middlewares') ?? [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if (in_array($middleware, $authMiddlewares, true) || $middleware === 'auth' || str_starts_with($middleware, 'auth:')) {
                return true;
            }
        }

        return false;
    }
}
