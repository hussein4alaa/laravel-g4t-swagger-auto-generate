<?php

namespace G4T\Swagger;

use ReflectionException;
use ReflectionMethod;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;

trait Helpers
{

    public function getControllerName(string $action): string
    {
        $segments = explode('\\', $action);
        $controller = end($segments);

        if (strpos($controller, '@') !== false) {
            $controller = explode('@', $controller)[0];
        }

        $controller = str_replace(['Controller', 'controller'], '', $controller);
        return $controller;
    }

    public function isApiRoute(object $route): bool
    {
        return in_array('api', $route->middleware());
    }


    public function getRouteName(string $route, string $prefix): string
    {
        $escapedPrefix = preg_quote($prefix, '/');
        $regex = "/{$escapedPrefix}\/([^\/]+)/";
        if (preg_match($regex, $route, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }


    public function generateOperationId(string $uri, string $method): string
    {
        $operationId = str_replace(['/', '[', ']'], '_', $uri) . '_' . $method;
        $operationId = str_replace(['{', '}'], '_', $operationId);

        return $operationId;
    }



    public function getRequestClassName(string $controllerMethod): array
    {
        if ($controllerMethod !== 'Closure') {
            $exploded = explode('@', $controllerMethod);

            if (!isset($exploded[0], $exploded[1])) {
                return [];
            }
            try {
                $class = $exploded[0];
                if (isset($exploded[1])) {
                    $method = $exploded[1];
                }
                $reflection = new ReflectionMethod($class, $method);
                $parameters = $reflection->getParameters() ?? [];
                foreach ($parameters as $parameter) {
                    $typeHint = $parameter ? $parameter->getType() : null;
                    if ($typeHint instanceof ReflectionNamedType && !$typeHint->isBuiltin()) {
                        try {
                            $request = $typeHint->getName();
                            $request = new $request();
                            return $request->rules();
                        } catch (\Throwable $th) {
                        }
                    }
                }
            } catch (ReflectionException $e) {
                return [];
            }
            return [];
        }
    }



    public function schemaName(string $action): string
    {
        $className = class_basename($action);
        $className = str_replace('Controller', '', $className);
        $className = strstr($className, '@', true);
        $methodName = Str::afterLast($action, '@');
        $modifiedClassName = Str::studly(ucfirst($className) . ucfirst($methodName));
        return $modifiedClassName;
    }


    public function checkIfQueryParamRequiredOrNot($params)
    {
        $required = false;
        if (!is_array($params)) {
            $params = explode('|', $params);
        }
        foreach ($params as $param) {
            if ($param == 'required') {
                $required = true;
                break;
            }
        }
        return $required;
    }


    public function getInputName(string $string, int $number = 0): string
    {
        $name = preg_replace('/\.(\w+)/', '[$1]', $string);
        if (str_contains($name, '.*')) {
            return str_replace(".*", "[merge_input]", $name);
        }
        return $name;
    }


    public function formatParams($validations, $route)
    {
        $method = $route->methods();
        $params_list = [];
        if (
            in_array($method, ['PUT', 'put', 'Put', 'PUT|PATCH']) or
            is_array($method) && in_array('GET', $method) or
            in_array('GET|HEAD', $method) or
            in_array('DELETE', $method) or
            in_array('DELETE|HEAD', $method)
        ) {
            if (!is_null($validations)) {
                foreach ($validations as $key => $param) {
                    $params_list[] = [
                        "name" => $this->getInputName($key),
                        "in" => "query",
                        "description" => $this->getInputName($key),
                        "required" => $this->checkIfQueryParamRequiredOrNot($param),
                        "schema" => $this->checkSchemaType($param)
                    ];
                }
            }
        }

        $required = $this->pathRequired($route);

        $params = $route->parameterNames();
        foreach ($params as $param) {
            $params_list[] = [
                "name" => $param,
                "in" => "path",
                "description" => $param,
                "required" => $required,
                "schema" => $this->checkSchemaType($param)
            ];
        }
        return $params_list;
    }


    private function checkSchemaType($param)
    {
        if (is_string($param)) {
            return $this->getSwaggerInputSchema($param);
        } else {
            $param = $this->convertValidationToOneLine($param);
            return $this->getSwaggerInputSchema($param);
        }
    }



    public function pathRequired($route)
    {
        $required = true;
        if (strpos($route->uri, '?') !== false) {
            $required = false;
        }
        return $required;
    }

    public function checkIfTokenIsRequired($route)
    {
        $middlewares = $route->gatherMiddleware();
        $authMiddlewares = config('swagger.auth_middlewares');

        foreach ($middlewares as $middleware) {
            if (in_array($middleware, $authMiddlewares)) {
                return true;
            }
        }

        return false;
    }
}
