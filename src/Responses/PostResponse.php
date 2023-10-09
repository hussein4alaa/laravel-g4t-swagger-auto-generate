<?php

namespace G4T\Swagger\Responses;

class PostResponse {

    public static function index($route)
    {
        $response = [
            "tags" => [
                "{$route['controller']}"
            ],
            "summary" => "Add {$route['name']}",
            "description" => "Add {$route['name']}",
            "operationId" => $route['operation_id'],
            "parameters" => $route['params'],
            "requestBody" => [
                "description" => "add {$route['name']}",
                "content" => [
                    "multipart/form-data" => [
                        "schema" => [
                            '$ref' => "#/components/schemas/{$route['schema_name']}"
                        ]
                    ],
                    "application/json" => [
                        "schema" => [
                            '$ref' => "#/components/schemas/Json{$route['schema_name']}"
                        ]
                    ],
                ],
                "required" => true
            ],
            "responses" => [
                "200" => [
                    "description" => "Successful operation",
                ],
                "422" => [
                    "description" => "Validation Issues"
                ]
            ],
            "security" => [
                [
                    "authorization" => []
                ],
            ]
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