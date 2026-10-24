<?php

namespace G4T\Swagger\Responses;

use G4T\Swagger\Support\FieldRules;
use Illuminate\Support\Str;

abstract class BaseResponse
{
    protected static function getResponses($method, $route)
    {
        $status = config('swagger.status') ?? [];
        $responses = $status[$method] ?? [];

        return is_array($responses) ? $responses : [];
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

        $dir = str_replace(['/', '{', '}', '?'], '-', $route['storage_uri'] ?? $route['uri']);
        $controller = $route['storage_controller'] ?? $route['controller'];
        $jsonDirPath = storage_path("swagger/{$controller}/{$dir}");
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

        $code = self::successStatus($route);
        if ($code === '204') {
            return;
        }

        foreach ($response['responses'] as $status => $definition) {
            if ((int) $status >= 200 && (int) $status < 300 && isset($definition['content']['application/json']['example'])) {
                return;
            }
        }

        $response['responses'][$code]['description'] = $response['responses'][$code]['description'] ?? self::defaultSuccessDescription((int) $code);
        $response['responses'][$code]['content']['application/json']['example'] = $route['response_example'];
    }

    /**
     * Every operation documents its success response (OpenAPI requires a non-empty responses object),
     * and protected routes document 401.
     */
    private static function ensureDefaultResponses(array &$response, array $route): void
    {
        $hasSuccess = false;
        foreach (array_keys($response['responses']) as $status) {
            if ((int) $status >= 200 && (int) $status < 300) {
                $hasSuccess = true;

                break;
            }
        }

        if (! $hasSuccess) {
            $code = self::successStatus($route);
            $response['responses'][$code] = ['description' => self::defaultSuccessDescription((int) $code)];
        }

        if (! empty($route['need_token']) && ! isset($response['responses']['401'])) {
            $response['responses']['401'] = [
                'description' => 'Unauthenticated',
                'content' => ['application/json' => ['example' => ['message' => 'Unauthenticated.']]],
            ];
        }

        ksort($response['responses'], SORT_STRING);
    }

    private static function successStatus(array $route): string
    {
        $status = (int) ($route['success_status'] ?? 200);

        return (string) ($status >= 200 && $status < 300 ? $status : 200);
    }

    private static function defaultSuccessDescription(int $code): string
    {
        return match ($code) {
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            default => 'OK',
        };
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
            foreach ($errorExamples as $statusCode => $raw) {
                $payloads = self::normalizeInferredErrorPayloads($raw);
                if ($payloads === []) {
                    continue;
                }
                $code = (string) $statusCode;
                $json = &$response['responses'][$code]['content']['application/json'];
                if (isset($json['example']) || isset($json['examples'])) {
                    continue;
                }
                $response['responses'][$code]['description'] = $response['responses'][$code]['description'] ?? self::defaultHttpErrorDescription((int) $code);
                if (count($payloads) === 1) {
                    $json['example'] = $payloads[0];
                } else {
                    $json['examples'] = self::buildOpenApiExamplesObject($payloads);
                }
                unset($json);
            }
        }

        if (! empty($route['validations']) && is_array($route['validations'])) {
            self::mergeValidation422Example($response, $route['validations']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeInferredErrorPayloads(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        if (array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }

        return [$raw];
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, array{summary?: string, value: array<string, mixed>}>
     */
    private static function buildOpenApiExamplesObject(array $payloads): array
    {
        $out = [];
        $usedNames = [];

        foreach ($payloads as $i => $payload) {
            $base = self::guessOpenApiExampleName($payload, $i);
            $name = $base;
            $n = 2;
            while (isset($usedNames[$name])) {
                $name = $base . '_' . $n;
                $n++;
            }
            $usedNames[$name] = true;
            $summary = isset($payload['message']) && is_string($payload['message'])
                ? Str::limit($payload['message'], 80)
                : 'Error response';
            $out[$name] = [
                'summary' => $summary,
                'value' => $payload,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function guessOpenApiExampleName(array $payload, int $index): string
    {
        if (isset($payload['message']) && is_string($payload['message'])) {
            $slug = Str::slug(Str::limit($payload['message'], 60, ''));
            if ($slug !== '') {
                return strlen($slug) > 56 ? substr($slug, 0, 56) : $slug;
            }
        }

        return 'error_' . ($index + 1);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function mergeValidation422Example(array &$response, array $rules): void
    {
        $validationExample = self::buildValidationErrorExample($rules);
        $response['responses']['422']['description'] = $response['responses']['422']['description'] ?? 'Unprocessable entity';

        $json = &$response['responses']['422']['content']['application/json'];

        if (! isset($json['example']) && ! isset($json['examples'])) {
            $json['example'] = $validationExample;

            return;
        }

        if (isset($json['examples']) && is_array($json['examples'])) {
            if (! isset($json['examples']['request_validation'])) {
                $json['examples']['request_validation'] = [
                    'summary' => 'Validation error (FormRequest)',
                    'value' => $validationExample,
                ];
            }

            return;
        }

        $previous = $json['example'];
        unset($json['example']);
        $examples = [];
        if (is_array($previous)) {
            $examples = self::buildOpenApiExamplesObject([$previous]);
            $firstKey = array_key_first($examples);
            if ($firstKey !== null) {
                $examples[$firstKey]['summary'] = $examples[$firstKey]['summary'] ?? 'Application error';
            }
        }
        $examples['request_validation'] = [
            'summary' => 'Validation error (FormRequest)',
            'value' => $validationExample,
        ];
        $json['examples'] = $examples;
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
            $code === 422 => 'Unprocessable entity',
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
        foreach ($rules as $field => $rule) {
            if (! self::validationRulesMarkFieldRequired($rule)) {
                continue;
            }
            $key = str_replace('*', '0', (string) $field);
            $label = str_replace(['_', '.*.', '.'], [' ', ' ', ' '], (string) $field);
            $errors[$key] = ["The {$label} field is required."];
        }

        return [
            'message' => 'The given data was invalid.',
            'errors' => $errors,
        ];
    }

    /**
     * Only fields that are unconditionally required (`required`, not `required_if` / `sometimes`).
     *
     * @param  mixed  $rule  pipe string, array of rules or a rule object
     */
    private static function validationRulesMarkFieldRequired(mixed $rule): bool
    {
        return FieldRules::parse($rule)->isRequired();
    }

    public static function index($route)
    {
        $response = [
            'tags' => [$route['controller']],
            'summary' => self::getSummary($route),
            'description' => (string) $route['description'],
            'operationId' => $route['operation_id'],
            'parameters' => array_values($route['params']),
            'responses' => self::getResponses(static::METHOD, $route),
        ];

        if (! empty($route['request_body'])) {
            $response['requestBody'] = $route['request_body'];
        }

        self::applyStoredResponseExamples($response, $route);
        self::applyInferredResponseExample($response, $route);
        self::applyInferredErrorExamples($response, $route);
        self::omitDefaultNotFoundForShow($response, $route);
        self::ensureDefaultResponses($response, $route);

        if ($route['need_token']) {
            $response['security'] = self::getSecurity($route);
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
