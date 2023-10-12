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