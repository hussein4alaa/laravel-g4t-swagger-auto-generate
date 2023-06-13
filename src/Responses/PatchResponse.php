<?php

namespace G4T\Swagger\Responses;

class PatchResponse {

    public static function index($route)
    {
        $response = [
            "tags" => [
                $route['controller']
            ],
            "summary" => "Update an existing {$route['name']}",
            "description" => "Update an existing {$route['name']} by id",
            "operationId" => $route['operation_id'],
            "parameters" => $route['params'],
            "requestBody" => [
                "description" => "Update an existent {$route['name']}",
                "content" => [
                    "application/json" => [
                        "schema" => [
                            '$ref' => "#/components/schemas/{$route['schema_name']}"
                        ]
                    ],
                ],
                "required" => true
            ],
            "responses" => [
                "200" => [
                    "description" => "Successful operation",
                ],
                "404" => [
                    "description" => "{$route['name']} not found"
                ],
                "405" => [
                    "description" => "Validation exception"
                ]
            ],
            "security" => config('swagger.security_schemes')
        ];
        if(!$route['need_token']) {
            unset($response['security']);
        }
        if (!$route['has_schema']) {
            unset($response['requestBody']);
        }
        return $response;
    }

}