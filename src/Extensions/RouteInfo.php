<?php

namespace G4T\Swagger\Extensions;

use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * The route a hook is called for.
 */
final class RouteInfo
{
    /**
     * @param  string  $method  HTTP method as documented (GET, POST, …)
     * @param  string  $uri  path with a leading slash, e.g. /api/v1/users/{user}
     * @param  string|null  $controller  controller class, null for closures
     * @param  string|null  $action  controller method (`__invoke` for invokable classes)
     * @param  string  $tag  section the operation is listed under
     */
    public function __construct(
        public Route $route,
        public string $method,
        public string $uri,
        public ?string $controller,
        public ?string $action,
        public string $operationId,
        public string $tag,
    ) {}

    public function reflection(): ?ReflectionMethod
    {
        if ($this->controller === null || $this->action === null) {
            return null;
        }

        try {
            return new ReflectionMethod($this->controller, $this->action);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first action parameter typed with $class or a subclass, e.g. a Data object.
     */
    public function parameterOfType(string $class): ?ReflectionParameter
    {
        foreach ($this->reflection()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_a($type->getName(), $class, true)) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Class of {@see parameterOfType()}, or null.
     *
     * @return class-string|null
     */
    public function parameterClass(string $class): ?string
    {
        $type = $this->parameterOfType($class)?->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    public function hasMiddleware(string $name): bool
    {
        return in_array($name, $this->route->gatherMiddleware(), true);
    }
}
