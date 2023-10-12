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

        $enable_response_schema = config('swagger.enable_response_schema');
        if($enable_response_schema) {
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
        return $response;
    }

}