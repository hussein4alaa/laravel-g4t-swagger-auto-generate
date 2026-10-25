<?php

namespace G4T\Swagger\Extensions;

use G4T\Swagger\Swagger;

/**
 * Base class for g4t/swagger extensions: teach the generator about code it cannot read on its own
 * (Spatie Laravel Data, Laravel Actions, JSON:API, your own DTOs or response helpers).
 *
 * Override only the hooks you need; each one is optional. Register the extension in
 * config('swagger.extensions') or with {@see Swagger::extend()} in a service provider.
 *
 * An exception thrown by a hook is logged (with APP_DEBUG) and ignored, so an extension can never
 * break the documentation.
 */
abstract class Extension
{
    /**
     * Request body or query parameters of a route. Return null to let the next extension, then the
     * built-in FormRequest rules, decide.
     */
    public function request(RouteInfo $route): ?RequestSpec
    {
        return null;
    }

    /**
     * Example for a value returned by a controller (or by a repository / helper it calls). Return null
     * when the expression is not yours. Examples made with {@see Example::fromClass()} or a component
     * name become reusable `$ref` schemas.
     */
    public function response(ReturnedValue $value): ?Example
    {
        return null;
    }

    /**
     * Change one OpenAPI operation after it is built (content types, extra responses, headers…).
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function operation(array $operation, RouteInfo $route): array
    {
        return $operation;
    }

    /**
     * Change the whole OpenAPI document at the end (extra components, x- fields, servers…).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function document(array $document): array
    {
        return $document;
    }
}
