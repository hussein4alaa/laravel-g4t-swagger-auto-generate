<?php
namespace G4T\Swagger\Responses;

use Faker\Factory as Faker;
use Illuminate\Support\Str;
use ReflectionClass;

abstract class BaseResponse
{
    private $schemas = [];
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

    protected static function addResponseExamples(&$response, $route, &$schemas)
    {
        $enable_response_schema = config('swagger.enable_response_schema');

        if ($enable_response_schema) {
            $resourceClass = self::inferResourceClass($route['action']);

            if ($resourceClass) {
                $mockData = self::generateMockDataFromResourceDocumentation($resourceClass);
                $response["responses"]["200"]["content"]["application/json"]["example"] = $mockData;

                $schema = self::generateSchemaFromResourceDocumentation($resourceClass, $schemas);
                $schemaName = $route['schema_name'] . "Response";

                $schemas[$schemaName] = $schema;
                $schemas[$schemaName]["xml"] = Str::lower($schemaName);
                $response["responses"]["200"]["content"]["application/json"]["schema"] = [
                    '$ref' => "#/components/schemas/{$schemaName}"
                ];
            }
        }
    }

    protected static function inferResourceClass($action)
    {
        $controller = explode('@', $action)[0];
        $method = explode('@', $action)[1];

        $controllerClass = new ReflectionClass($controller);
        $methods = $controllerClass->getMethods();
        foreach ($methods as $controllerMethod) {
            if ($controllerMethod->getName() === $method) {
                $docComment = $controllerMethod->getDocComment();
                if (preg_match('/@resource\s+(\S+)/', $docComment, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    protected static function getSchemaNameForResource($resourceClass)
    {
        $parts = explode('\\', $resourceClass);
        $shortName = end($parts);

        return $shortName;
    }

    protected static function extractResourcePathAndExtension(string $line, &$extension)
    {
        // Find the start of the value, which is after the colon and any potential whitespace
        $start = strpos($line, ':') + 1;

        // Find the end of the value, which is before the closing quotation mark
        $end = strrpos($line, '"');

        // Extract the part between the start and end positions
        $value = substr($line, $start, $end - $start);

        // Remove any leading or trailing whitespace and quotes
        $value = trim($value, ' "');

        // Define the patterns to match suffixes
        $patterns = [
            '/(\[\]|\[\]\?)$/', // Match [] or []? at the end
            '/\?$/', // Match ? at the end
        ];

        // Initialize $extension to an empty string
        $extension = '';

        // Check and extract suffix
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $matches)) {
                $extension = $matches[0]; // Store the matched suffix
                $value = preg_replace($pattern, '', $value); // Remove the suffix from the value
                break; // Remove only the first match
            }
        }

        return $value;
    }

    protected static function replaceLinesWithBackslash(string $text, &$extension)
    {
        // Find lines with backslashes
        $linesWithBackslash = self::findLinesWithBackslash($text);
        // Split the text into an array of lines
        $lines = explode("\n", $text);

        // Process each line and replace the ones with backslashes
        foreach ($lines as &$line) {
            foreach ($linesWithBackslash as $lineWithBackslash) {
                if (trim($line) === trim($lineWithBackslash)) {
                    // Replace the entire line with "string"
                    $className = self::extractResourcePathAndExtension($line, $extension);
                    $mockData = self::generateMockDataFromResourceDocumentation($className);
                    $line = str_replace(substr($line, strpos($line, ':') + 1), json_encode($mockData), $line);
                    break;
                }
            }
        }

        // Join the lines back into a single string
        return implode("\n", $lines);
    }


    protected static function replaceLinesWithBackslashSchema(string $text, &$classname, &$schemas)
    {
        // Find lines with backslashes
        $linesWithBackslash = self::findLinesWithBackslash($text);
        // Split the text into an array of lines
        $lines = explode("\n", $text);

        // Process each line and replace the ones with backslashes
        foreach ($lines as &$line) {
            foreach ($linesWithBackslash as $lineWithBackslash) {
                if (trim($line) === trim($lineWithBackslash)) {
                    // Replace the entire line with "string"
                    // $extension = '';
                    $extension = "";
                    $className = self::extractResourcePathAndExtension($line, $extension);
                    $subSchema = self::generateSchemaFromResourceDocumentation($className, $schema);
                    $reflection = new ReflectionClass($className);
                    $schemaName = $reflection->getShortName();
                    $schemas[$schemaName] = $subSchema;
                    $schemas[$schemaName]["xml"] = Str::lower($schemaName);
                    $isArray = strpos($extension, "[]") !== false;
                    $isOptional = strpos($extension, "?") !== false;
                    $schemaPath = "#/components/schemas/{$schemaName}";
                    $schemaToMatch = [];
                    if ($isArray) {
                        $schemaToMatch['type'] = "array";
                        $schemaToMatch['items'] = [
                            '$ref' => $schemaPath
                        ];
                    } else {
                        $schemaToMatch['$ref'] = $schemaPath;
                    }
                    if ($isOptional) {
                        $schemaToMatch['nullable'] = true;
                    }
                    $line = str_replace(substr($line, strpos($line, ':') + 1), json_encode($schemaToMatch), $line);
                    break;
                }
            }
        }

        // Join the lines back into a single string
        return implode("\n", $lines);
    }
    protected static function findLinesWithBackslash(string $lines)
    {
        $lines = explode("\n", $lines); // Split the string into an array of lines
        $result = [];
        foreach ($lines as $line) {
            if (strpos($line, '\\') !== false) {
                $result[] = $line;
            }
        }
        return $result;
    }

    protected static function generateMockDataFromResourceDocumentation($resourceClass)
    {
        $faker = Faker::create();
        $reflection = new ReflectionClass($resourceClass);
        $method = $reflection->getMethod('toArray');
        $docComment = $method->getDocComment();

        if (preg_match('/@attributes\s*\{(.+?)\}/s', $docComment, $matches)) {
            $attributes = $matches[1];
            $extension = '';
            $attributes = self::replaceLinesWithBackslash($attributes, $extension);
            $attributes = preg_replace('/\*\s*/', '', $attributes);
            $attributes = trim($attributes);
            $attributes = str_replace(['“', '”'], '"', $attributes);

            // Handle types (arrays and optional fields)
            $attributes = preg_replace('/(\w+):\s*"(\w+)\[\]?\??"/', '"$1": {"type": "array", "items": {"type": "$2"}}', $attributes);
            $attributes = preg_replace('/(\w+):\s*"(\w+)\??"/', '"$1": {"type": "$2"}', $attributes);

            // Remove trailing commas if any
            $attributes = rtrim($attributes, ',');
            $attributes = '{' . $attributes . '}';
            $attributesArray = json_decode($attributes, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode JSON: ' . json_last_error_msg());
            }
            $isArray = strpos($extension, '[]') !== false;
            return self::generateMockDataForAttributes($attributesArray, $faker, $isArray);
        }

        throw new \RuntimeException('No attributes found in doc comment.');
    }

    protected static function generateMockDataForAttributes(array $attributesArray, $faker, $isArrayType)
    {

        $data = [];
        foreach ($attributesArray as $key => $type) {
            if (is_array($type)) {
                $generatedData = self::generateMockDataForAttributes($type, $faker, false);
                $data[$key] = $isArrayType ? [$generatedData, $generatedData, $generatedData] : $generatedData;
            } else {
                $isArray = strpos($type, '[]') !== false;
                $baseType = trim($type, '?[]'); // Trim both `?` and `[]` for optional arrays

                if ($isArray) {
                    $mockArray = self::generateMockArrayForType($baseType, $faker);
                    $data[$key] = $mockArray;
                } else {
                    $mockValue = self::generateMockDataForType($baseType, $faker);
                    $data[$key] = $mockValue;
                }
            }
        }
        return $data;
    }

    protected static function generateMockArrayForType($type, $faker)
    {
        $arraySize = rand(1, 5); // Random array size between 1 and 5
        $arrayData = [];

        for ($i = 0; $i < $arraySize; $i++) {
            $arrayData[] = self::generateMockDataForType($type, $faker);
        }

        return $arrayData;
    }

    protected static function generateMockDataForType($type, $faker)
    {
        switch ($type) {
            case 'string':
                return "string";
            case 'number':
            case 'integer':
            case 0:
                return 0;
            case 'boolean':
            case true:
                return true;
            default:
                return $type;
        }
    }

    protected static function generateSchemaFromResourceDocumentation($resourceClass, &$schemas)
    {
        $reflection = new ReflectionClass($resourceClass);
        $method = $reflection->getMethod('toArray');
        $docComment = $method->getDocComment();

        $schema = [
            "type" => "object",
            "properties" => [],
            "required" => [],
        ];

        if (preg_match('/@attributes\s*\{(.+?)\}/s', $docComment, $matches)) {
            $attributes = $matches[1];
            $attributes = preg_replace('/\*\s*/', '', $attributes);
            $attributes = trim($attributes);
            $attributes = str_replace(['“', '”'], '"', $attributes);
            $attributes = self::replaceLinesWithBackslashSchema($attributes, $extension, $schemas);
            $attributes = preg_replace('/(\w+):\s*"(\w+)\[\]?\??"/', '"$1": {"type": "array", "items": {"type": "$2"}}', $attributes);
            $attributes = preg_replace('/(\w+):\s*"(\w+)\??"/', '"$1": {"type": "$2"}', $attributes);
            $attributes = rtrim($attributes, ',');
            $attributes = '{' . $attributes . '}';

            $attributesArray = json_decode($attributes, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode JSON: ' . json_last_error_msg());
            }
            foreach ($attributesArray as $key => $type) {
                $schema['properties'][$key] = self::mapTypeToSchemaType($type);
            }

            $schema['required'] = array_keys($schema['properties']);
        }

        return $schema;
    }

    protected static function mapTypeToSchemaType($type)
    {
        if (is_array($type))
            return $type;
        $isNullable = strpos($type, '?') !== false;
        $isArray = strpos($type, '[]') !== false;
        $baseType = trim($type, '?[]');

        $schemaType = [
            'type' => $isArray ? 'array' : $baseType
        ];

        if ($isArray) {
            $schemaType['items'] = self::mapTypeToSchemaType($baseType);
        }

        if ($isNullable) {
            $schemaType['nullable'] = true;
        }

        return $schemaType;
    }

    public static function index($route, &$schemas)
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

        if ($route['has_schema']) {
            $response['requestBody'] = [
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
        self::addResponseExamples($response, $route, $schemas);

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
