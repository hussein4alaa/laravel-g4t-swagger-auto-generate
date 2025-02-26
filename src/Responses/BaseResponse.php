<?php

namespace G4T\Swagger\Responses;

abstract class BaseResponse
{
    protected static function getResponses($method, $route)
    {
        $status = config('swagger.status');
        return isset($status[$method]) ? $status[$method] : [
            "200" => ["description" => "Successful operation"],
            "404" => ["description" => "{$route['name']} not found"],
            "405" => ["description" => "Validation exception"]
        ];
    }

    private static function getSummary($route)
    {
        return !is_null($route['summary']) ? $route['summary'] : $route['name'];
    }

    protected static function addResponseExamples(&$response, $route)
    {
        $enable_response_schema = config('swagger.enable_response_schema');
        if ($enable_response_schema) {
            $dir = str_replace(['/', '{', '}', '?'], '-', $route['uri']);
            $jsonDirPath = storage_path("swagger/{$route['controller']}/{$dir}");
            if (is_dir($jsonDirPath)) {
                $files = glob($jsonDirPath . '/*.json');
                foreach ($files as $file) {
                    $parts = explode('/', rtrim($file, '/'));
                    $lastPart = end($parts);
                    if (preg_match('/(\d+)\.json$/', $lastPart, $matches)) {
                        $statusCode = $matches[1];
                        $jsonContent = json_decode(file_get_contents($file), true);
                        $response["responses"]["$statusCode"]["description"] = $jsonContent['status_text'];
                        $response["responses"]["$statusCode"]["content"]["application/json"]["example"] = $jsonContent['response'];
                    }
                }
            }
        }
    }


    private static function postBody($route)
    {
        return [
            "description" => "{$route['description']}",
            "content" => [
                "multipart/form-data" => [
                    "schema" => [
                        '$ref' => "#/components/schemas/{$route['schema_name']}"
                    ]
                ],
                "application/json" => [
                    "schema" => [
                        '$ref' => "#/components/schemas/{$route['schema_name']}"
                    ]
                ],
            ],
            "required" => true
        ];
    }

    private static function putBody($route)
    {
        return [
            "description" => "{$route['description']}",
            "content" => [
                "application/json" => [
                    "schema" => [
                        '$ref' => "#/components/schemas/{$route['schema_name']}"
                    ]
                ],
                "multipart/form-data" => [
                    "schema" => [
                        '$ref' => "#/components/schemas/{$route['schema_name']}"
                    ]
                ],
            ],
            "required" => true
        ];
    }

    public static function index($route)
    {
        $response = [
            "tags" => [$route['controller']],
            "summary" => self::getSummary($route),
            "description" => "{$route['description']}",
            "operationId" => $route['operation_id'],
            "parameters" => $route['params'],
            "responses" => self::getResponses(static::METHOD, $route),
            "security" => self::getSecurity($route)
        ];

        // Add requestBody if schema is available
        if ($route['has_schema']) {
            if(static::METHOD == 'PUT' || static::METHOD == 'PATCH') {
                $response['requestBody'] = self::putBody($route);
            } else {
                $response['requestBody'] = self::postBody($route);
            }
        }

        $enable_response_schema = config('swagger.enable_response_schema');
        if ($enable_response_schema) {
            $dir = str_replace(['/', '{', '}', '?'], '-', $route['uri']);
            $jsonDirPath = storage_path("swagger/{$route['controller']}/{$dir}");
            if (is_dir($jsonDirPath)) {
                $files = glob($jsonDirPath . '/*.json');
                foreach ($files as $file) {
                    $parts = explode('/', rtrim($file, '/'));
                    $lastPart = end($parts);
                    if (preg_match('/(\d+)\.json$/', $lastPart, $matches)) {
                        $statusCode = $matches[1];
                        $jsonContent = json_decode(file_get_contents($file), true);
                        $response["responses"]["$statusCode"]["description"] = $jsonContent['status_text'];
                        $response["responses"]["$statusCode"]["content"]["application/json"]["example"] = $jsonContent['response'];
                    }
                }
            }
        }

        if ($route['need_token']) {
            $security_array = [];
            $security_schemes = config('swagger.security_schemes');
            foreach ($security_schemes as $key => $security_scheme) {
                $security_array[] = [$key => []];
            }
            $response['security'] = $security_array;
        } else {
            unset($response['security']);
        }

        return $response;
    }

    protected static function getSecurity($route)
    {
        if ($route['need_token']) {
            $security_array = [];
            $security_schemes = config('swagger.security_schemes');
            foreach ($security_schemes as $key => $security_scheme) {
                $security_array[] = [$key => []];
            }
            return $security_array;
        }
        return [];
    }
}