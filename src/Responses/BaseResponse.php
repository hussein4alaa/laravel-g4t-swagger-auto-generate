<?php

namespace G4T\Swagger\Responses;

abstract class BaseResponse
{
    protected static function getResponses($method, $route)
    {
        $status = config('swagger.status') ?? [];

        return $status[$method] ?? [];
    }

    private static function getSummary($route)
    {
        return ! is_null($route['summary']) ? $route['summary'] : $route['name'];
    }

    /**
     * Merge examples from storage/swagger JSON files (status code in filename).
     */
    private static function applyStoredResponseExamples(array &$response, array $route): void
    {
        if (! config('swagger.enable_response_schema')) {
            return;
        }

        $dir = str_replace(['/', '{', '}', '?'], '-', $route['uri']);
        $jsonDirPath = storage_path("swagger/{$route['controller']}/{$dir}");
        if (! is_dir($jsonDirPath)) {
            return;
        }

        $files = glob($jsonDirPath . '/*.json');
        if (! $files) {
            return;
        }

        foreach ($files as $file) {
            $parts = explode('/', rtrim($file, '/'));
            $lastPart = end($parts);
            if (! preg_match('/(\d+)\.json$/', $lastPart, $matches)) {
                continue;
            }
            $statusCode = $matches[1];
            $jsonContent = json_decode(file_get_contents($file), true);
            if (! is_array($jsonContent)) {
                continue;
            }
            $response['responses'][$statusCode]['description'] = $jsonContent['status_text'] ?? $response['responses'][$statusCode]['description'] ?? '';
            $response['responses'][$statusCode]['content']['application/json']['example'] = $jsonContent['response'];
        }
    }

    /**
     * When no stored example exists for HTTP 200, use the controller/resource inference.
     */
    private static function applyInferredResponseExample(array &$response, array $route): void
    {
        if (! array_key_exists('response_example', $route) || $route['response_example'] === null || ! config('swagger.infer_response_examples', true)) {
            return;
        }

        $has200Example = isset($response['responses']['200']['content']['application/json']['example']);
        if ($has200Example) {
            return;
        }

        $response['responses']['200']['description'] = $response['responses']['200']['description'] ?? 'OK';
        $response['responses']['200']['content']['application/json']['example'] = $route['response_example'];
    }

    /**
     * Merge inferred error examples (response()->json payload + status, abort(), Laravel-style 422 validation).
     */
    private static function applyInferredErrorExamples(array &$response, array $route): void
    {
        if (! config('swagger.infer_error_response_examples', true)) {
            return;
        }

        $errorExamples = $route['error_response_examples'] ?? [];
        if ($errorExamples !== [] && is_array($errorExamples)) {
            foreach ($errorExamples as $statusCode => $example) {
                if (! is_array($example)) {
                    continue;
                }
                $code = (string) $statusCode;
                if (isset($response['responses'][$code]['content']['application/json']['example'])) {
                    continue;
                }
                $response['responses'][$code]['description'] = $response['responses'][$code]['description'] ?? self::defaultHttpErrorDescription((int) $code);
                $response['responses'][$code]['content']['application/json']['example'] = $example;
            }
        }

        if (! empty($route['validations']) && is_array($route['validations']) && ! isset($response['responses']['422']['content']['application/json']['example'])) {
            $response['responses']['422']['description'] = $response['responses']['422']['description'] ?? 'Validation error';
            $response['responses']['422']['content']['application/json']['example'] = self::buildValidationErrorExample($route['validations']);
        }
    }

    /**
     * Drop the default 404 "Not Found" response for `show` actions when it has no example/schema
     * (config status GET 404 only). Keeps 404 when inferred errors or storage/swagger JSON added content.
     */
    private static function omitDefaultNotFoundForShow(array &$response, array $route): void
    {
        if (! config('swagger.omit_default_404_for_show', true)) {
            return;
        }

        if (static::METHOD !== 'GET') {
            return;
        }

        $action = $route['action'] ?? '';
        if (! is_string($action) || ! str_contains($action, '@')) {
            return;
        }

        [, $methodName] = explode('@', $action, 2);
        if ($methodName !== 'show') {
            return;
        }

        if (! isset($response['responses']['404'])) {
            return;
        }

        $notFound = $response['responses']['404'];
        if (isset($notFound['content']) && $notFound['content'] !== []) {
            return;
        }

        unset($response['responses']['404']);
    }

    private static function defaultHttpErrorDescription(int $code): string
    {
        return match (true) {
            $code === 401 => 'Unauthorized',
            $code === 403 => 'Forbidden',
            $code === 404 => 'Not found',
            $code === 409 => 'Conflict',
            $code === 422 => 'Validation error',
            $code === 429 => 'Too many requests',
            $code >= 500 => 'Server error',
            default => 'Error',
        };
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{message: string, errors: array<string, list<string>>}
     */
    private static function buildValidationErrorExample(array $rules): array
    {
        $errors = [];
        foreach (array_keys($rules) as $field) {
            $errors[$field] = ["The {$field} field is required."];
        }

        return [
            'message' => 'The given data was invalid.',
            'errors' => $errors,
        ];
    }

    public static function index($route)
    {
        $response = [
            'tags' => [$route['controller']],
            'summary' => self::getSummary($route),
            'description' => "{$route['description']}",
            'operationId' => $route['operation_id'],
            'parameters' => $route['params'],
            'responses' => self::getResponses(static::METHOD, $route),
            'security' => self::getSecurity($route),
        ];

        if ($route['has_schema']) {
            $response['requestBody'] = [
                'description' => "{$route['description']}",
                'content' => [
                    'multipart/form-data' => [
                        'schema' => [
                            '$ref' => "#/components/schemas/{$route['schema_name']}",
                        ],
                    ],
                    'application/json' => [
                        'schema' => [
                            '$ref' => "#/components/schemas/{$route['schema_name']}",
                        ],
                    ],
                ],
                'required' => true,
            ];
        }

        self::applyStoredResponseExamples($response, $route);
        self::applyInferredResponseExample($response, $route);
        self::applyInferredErrorExamples($response, $route);
        self::omitDefaultNotFoundForShow($response, $route);

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
