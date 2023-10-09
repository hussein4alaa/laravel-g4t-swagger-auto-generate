<?php

namespace G4T\Swagger;

use ReflectionException;
use ReflectionMethod;
use Illuminate\Support\Str;

trait Helpers
{

    public function getControllerName($action)
    {
        $segments = explode('\\', $action);
        $controller = end($segments);

        if (strpos($controller, '@') !== false) {
            $controller = explode('@', $controller)[0];
        }

        $controller = str_replace(['Controller', 'controller'], '', $controller);
        return $controller;
    }

    public function isApiRoute($route)
    {
        return in_array('api', $route->middleware());
    }


    public function getRouteName($route, $prefix)
    {
        $escapedPrefix = preg_quote($prefix, '/');
        $regex = "/{$escapedPrefix}\/([^\/]+)/";
        if (preg_match($regex, $route, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }


    public function generateOperationId($uri, $method)
    {
        $operationId = str_replace(['/', '[', ']'], '_', $uri) . '_' . $method;
        $operationId = str_replace(['{', '}'], '_', $operationId);

        return $operationId;
    }



    public function getRequestClassName(string $controllerMethod)
    {
        if ($controllerMethod !== 'Closure') {
            list($class, $method) = explode('@', $controllerMethod);
            try {
                $reflection = new ReflectionMethod($class, $method);
                $parameters = $reflection->getParameters() ?? [];
                foreach ($parameters as $parameter) {
                    $typeHint = $parameter ? $parameter->getType() : null;
                    if ($typeHint && !$typeHint->isBuiltin()) {
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
            return null;
        }
    }


    public function schemaName($action)
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


    public function getInputName($string)
    {
        return preg_replace('/\.(\w+)/', '[$1]', $string);
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
                        "description" => $key,
                        "required" => $this->checkIfQueryParamRequiredOrNot($param),
                        "schema" => [
                            "type" => "string"
                        ]
                    ];
                }
            }
        }

        $params = $route->parameterNames();
        foreach ($params as $param) {
            $params_list[] = [
                "name" => $param,
                "in" => "path",
                "description" => $param,
                "required" => true,
                "schema" => [
                    "type" => "string"
                ]
            ];
        }
        return $params_list;
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
