<?php

namespace G4T\Swagger;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionException;
use ReflectionMethod;

trait Helpers
{
    /**
     * Get pure name of controller
     *
     * @param string $action
     * @return array<string>|string
     */
    public function getControllerName(string $action)
    {
        $controller = class_basename($action);
        $controller = Str::contains($controller, '@') ? Str::beforeLast($controller, '@') : $controller;
        $controller = Str::replace(['Controller', 'controller'], '', $controller);

        return $controller;
    }

    /**
     * Check if the route is an API request
     *
     * @param Route $route
     * @return bool
     */
    public function isApiRoute(Route $route)
    {
        return in_array('api', $route->middleware());
    }

    /**
     * Name of route after prefix
     *
     * @param string $route
     * @param string $prefix
     * @return string
     */
    public function getRouteName(string $route, string $prefix)
    {
        if (Str::startsWith($route, $prefix . '/')) {
            $trimmedRoute = Str::after($route, $prefix . '/');
            $routeName = Str::before($trimmedRoute, '/');
            return $routeName;
        }

        return 'unknown';
    }

    /**
     * Generate Operation Id
     *
     * @param string $uri
     * @param string $method
     * @return array<string>|string
     */
    public function generateOperationId(string $uri, string $method)
    {
        return Str::replace(['/', '[', ']', '{', '}'], ['_', '_', '_', '_', '_'], $uri . '_' . $method);
    }

    /**
     * Get validation of request for controller's method
     *
     * @param string $controllerMethod
     * @return mixed
     */
    public function getRequestClassName(string $controllerMethod)
    {
        if ($controllerMethod === 'Closure') {
            return null;
        }

        list($class, $method) = explode('@', $controllerMethod);

        try {
            $parameters = $this->getMethodParameters($class, $method);

            foreach ($parameters as $parameter) {
                $typeHint = $parameter->getType();

                if ($typeHint && !$typeHint->isBuiltin()) {
                    try {
                        $request = $typeHint->getName();
                        return $this->getRequestRules($request);
                    } catch (\Throwable $th) {
                    }
                }
            }
        } catch (ReflectionException $e) {
        }

        return [];
    }

    /**
     * Get parameters of method
     *
     * @param string $class
     * @param string $method
     * @return array<\ReflectionParameter>
     */
    private function getMethodParameters(string $class, string $method)
    {
        $reflection = new ReflectionMethod($class, $method);
        return $reflection->getParameters() ?? [];
    }

    /**
     * Get request rules of controller's method
     *
     * @param string $requestClass
     * @return mixed
     */
    private function getRequestRules(string $requestClass)
    {
        $request = new $requestClass();
        return $request->rules();
    }

    /**
     * Get schema Name of controller and method
     *
     * @param string $action
     * @return string
     */
    public function schemaName(string $action)
    {
        $className = class_basename($action);
        $className = Str::replaceLast('Controller', '', $className);
        $className = Str::before($className, '@');
        $methodName = Str::afterLast($action, '@');
        $modifiedClassName = Str::studly(ucfirst($className) . ucfirst($methodName));

        return $modifiedClassName;
    }

    /**
     * Check if query parameter is required or not
     *
     * @param array|string $params
     * @return bool
     */
    public function checkIfQueryParamRequiredOrNot(mixed $params)
    {
        if (!is_array($params)) {
            $params = explode('|', $params);
        }

        return in_array('required', $params);
    }

    /**
     * Get format Parammters of controller's method
     *
     * @param mixed $validations
     * @param Route $route
     * @return array<array>
     */
    public function formatParams(mixed $validations, Route $route)
    {
        $method = $route->methods();
        $params_list = [];

        if (in_array('PUT', $method) || in_array('GET', $method)) {
            if (filled($validations)) {
                foreach ($validations as $key => $param) {
                    $params_list[] = [
                        "name" => $key,
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

    /**
     * Check if token is required or not
     *
     * @param Route $route
     * @return bool
     */
    public function checkIfTokenIsRequired(Route $route)
    {
        $middlewares = $route->gatherMiddleware();
        $authMiddlewares = config('swagger.auth_middlewares');

        return count(array_intersect($middlewares, $authMiddlewares)) > 0;
    }
}
