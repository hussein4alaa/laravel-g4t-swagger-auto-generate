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
                    "authorization" => [],
                    "apiKey1" => []
                ],
            ]
        ];
        if (count($route['params']) == 0) {
            unset($response['parameters']);
        }
        if (!$route['has_schema']) {
            unset($response['requestBody']);
        }
        if($route['need_token']) {
            $security_array = [];
            $security_schemes = config('swagger.security_schemes');
            foreach ($security_schemes as $key => $security_scheme) {
                $security_array[] = [
                    $key => []
                ];
            }
            $response['security'] = $security_array;
        } else {
            unset($response['security']);
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