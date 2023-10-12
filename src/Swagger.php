<?php

namespace G4T\Swagger;

use G4T\Swagger\Sections\Paths;
use G4T\Swagger\Sections\Schemas;
use G4T\Swagger\Sections\Tags;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use stdClass;

class Swagger
{

    use Tags, Paths, Helpers, Schemas;

    /**
     * Generate the Swagger documentation.
     *
     * @return array
     */
    public function swagger()
    {
        return [
            "openapi" => "3.0.3",
            "info" => [
                "title" => config('swagger.title'),
                "description" => config('swagger.description'),
                "termsOfService" => "http://swagger.io/terms/",
                "contact" => [
                    "email" => config('swagger.email'),
                ],
                "license" => [
                    "name" => "Github",
                    "url" => "https://github.com/hussein4alaa"
                ],
                "version" => config('swagger.version')
            ],
            "servers" => config('swagger.servers'),
            "components" => $this->generateSwaggerJsonResponse(),
            "tags" => $this->generateSwaggerJsonResponse()->tags,
            "paths" => $this->generateSwaggerJsonResponse()->paths,
        ];
    }



    /**
     * Generate the Swagger JSON response for API documentation.
     *
     * This function retrieves all routes defined in the application and filters out the API routes. It collects relevant information for each API route, including prefix, action name, route name, HTTP method, URI, operation ID, validations, and schema name. The function determines if a route has a schema based on the presence of validations. It also checks if a token is required for the route.
     *
     * The collected information is used to build an array of API routes, which includes details such as the route's prefix, method, URI, name, schema name, action, middleware, validations, parameters, operation ID, whether it has a schema, and whether a token is required.
     *
     * Additionally, the function collects all the route names to generate tags for the Swagger documentation. It formats the collected API routes and constructs the JSON data structure required for Swagger documentation. This JSON data includes tags, paths, schemas, and security schemes.
     *
     * The function returns the generated Swagger JSON response as an object.
     *
     * @return object The Swagger JSON response for API documentation.
     */

    public function generateSwaggerJsonResponse()
    {
        $routes = Route::getRoutes();
        $apiRoutes = [];
        $names = [];
        $schemas = [];
        $show_prefix_array = config('swagger.show_prefix');
        $mapping_prefix = config('swagger.mapping_prefix');

        foreach ($routes as $route) {
            if ($this->isApiRoute($route)) {
                $prefix = $route->getPrefix();
                $action = ltrim($route->getActionName(), '\\');
                $controller = $this->getControllerName($route->getAction('controller'));
                $routeName = $this->getRouteName($route->uri(), $prefix);
                $method = implode('|', $route->methods());
                $uri = '/'.$route->uri();
                $operationId = $this->generateOperationId($uri, $method);
                $validations = $this->getRequestClassName($action);
                $schemaName = $this->schemaName($action);

                if ($action !== 'Closure') {
                    $prefix_for_condition = isset($show_prefix_array) && count($show_prefix_array) > 0 ? $show_prefix_array : ["$prefix"];
                    if (in_array($prefix, $prefix_for_condition)) {
                    $hasSchema = false;

                    if (isset($mapping_prefix[$prefix])) {
                        $uri = str_replace($prefix, $mapping_prefix[$prefix], $uri);
                        $prefix = $mapping_prefix[$prefix];
                    }
                    
                    if (!is_null($validations) && count($validations) > 0) {
                        $hasSchema = true;
                        if($method == 'POST') {
                            $schemas[$schemaName] = $this->getSchemas($validations, $schemaName, $method);
                            $schemas["Json$schemaName"] = $this->getSchemas($validations, "Json$schemaName", "PUT");
                        } else {
                            $schemas[$schemaName] = $this->getSchemas($validations, $schemaName, $method);
                        }
                    }
         

                    $needToken = $this->checkIfTokenIsRequired($route);

                    $apiRoutes[] = [
                        'prefix' => $prefix,
                        'method' => $method,
                        'controller' => $controller,
                        'uri' => $uri,
                        'name' => $routeName,
                        'schema_name' => $schemaName,
                        'action' => $action,
                        'middleware' => $route->middleware(),
                        'validations' => $validations,
                        'params' => $this->formatParams($validations, $route),
                        'operation_id' => $operationId,
                        'has_schema' => $hasSchema,
                        'need_token' => $needToken
                    ];

                    $names[] = $controller;
                    }

                }
            }

        }

        $swaggerJson = new stdClass();
        $swaggerJson->tags = $this->getTags($names);
        $swaggerJson->paths = $this->formatPaths($apiRoutes);
        $swaggerJson->schemas = $schemas;
        $swaggerJson->securitySchemes = config('swagger.security_schemes');

        return $swaggerJson;
    }


}
