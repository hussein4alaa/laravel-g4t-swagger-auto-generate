<?php

namespace G4T\Swagger\Responses;

class GetResponse {

    public static function index($route)
    {
        $response = [
            "tags" => [
                "{$route['controller']}"
            ],
            "summary" => "{$route['name']}",
            "description" => "{$route['name']}",
            "operationId" => $route['operation_id'],
            "parameters" => $route['params'],
            "responses" => [
                "200" => [
                    "description" => "successful operation",
                ],
                "404" => [
                    "description" => "page not found"
                ]
            ],
            "security" => [
                [
                    "authorization" => []
                ],
            ]
        ];
        if (count($route['params']) == 0) {
            unset($response['parameters']);
        }
        if (!$route['has_schema']) {
            unset($response['requestBody']);
        }
        return $response;
    }


}