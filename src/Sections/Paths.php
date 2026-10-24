<?php

namespace G4T\Swagger\Sections;

use G4T\Swagger\Responses\DeleteResponse;
use G4T\Swagger\Responses\GetResponse;
use G4T\Swagger\Responses\PatchResponse;
use G4T\Swagger\Responses\PostResponse;
use G4T\Swagger\Responses\PutResponse;

trait Paths
{
    /**
     * Group operations by path. Optional parameters (`{year?}`) are expanded into one path per variant,
     * because OpenAPI path parameters are always required.
     *
     * @param  list<array<string, mixed>>  $routes
     * @return array<string, array<string, mixed>>
     */
    public function formatPaths(array $routes): array
    {
        $responders = [
            'GET' => GetResponse::class,
            'POST' => PostResponse::class,
            'PUT' => PutResponse::class,
            'PATCH' => PatchResponse::class,
            'DELETE' => DeleteResponse::class,
        ];

        $realOperations = [];
        foreach ($routes as $route) {
            foreach ($this->pathVariants($route['uri']) as $variant) {
                $realOperations[$variant['uri']][$route['original_method'] ?? $route['method']] = true;
            }
        }

        $groupedData = [];
        foreach ($routes as $route) {
            foreach ($this->pathVariants($route['uri']) as $variant) {
                $method = $route['method'];
                // A spoofed POST (file upload on PUT/PATCH) must not hide a real POST on the same path.
                if ($method !== ($route['original_method'] ?? $method) && isset($realOperations[$variant['uri']][$method])) {
                    $method = $route['original_method'];
                }
                if (! isset($responders[$method])) {
                    continue;
                }

                $variantRoute = $route;
                $variantRoute['uri'] = $variant['uri'];
                $variantRoute['params'] = array_merge(
                    $route['query_params'] ?? [],
                    $this->pathParametersFor($variant['parameters'], $route['path_params'] ?? []),
                );
                if (count($this->pathVariants($route['uri'])) > 1) {
                    $variantRoute['operation_id'] = $this->generateOperationId($variant['uri'], $method);
                }

                $groupedData[$variant['uri']][strtolower($method)] = $responders[$method]::index($variantRoute);
            }
        }

        return $groupedData;
    }

    /**
     * @return list<array{uri: string, parameters: list<string>}>
     */
    private function pathVariants(string $uri): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);
        $required = [];
        $optional = [];
        foreach ($matches as $match) {
            if (($match[2] ?? '') === '?') {
                $optional[] = $match[1];
            } else {
                $required[] = $match[1];
            }
        }

        $full = preg_replace('/\{(\w+)\?\}/', '{$1}', $uri);
        if ($optional === []) {
            return [['uri' => $full, 'parameters' => $required]];
        }

        $variants = [];
        for ($kept = count($optional); $kept >= 0; $kept--) {
            $variantUri = $full;
            foreach (array_slice($optional, $kept) as $dropped) {
                $variantUri = preg_replace('#/?\{'.preg_quote($dropped, '#').'\}#', '', $variantUri);
            }
            $variants[] = [
                'uri' => $variantUri === '' ? '/' : $variantUri,
                'parameters' => array_merge($required, array_slice($optional, 0, $kept)),
            ];
        }

        return $variants;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, array<string, mixed>>  $schemas
     * @return list<array<string, mixed>>
     */
    private function pathParametersFor(array $names, array $schemas): array
    {
        $parameters = [];
        foreach ($names as $name) {
            $schema = $schemas[$name] ?? ['type' => 'string'];
            $description = $schema['description'] ?? $name;
            unset($schema['description']);
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'description' => $description,
                'required' => true,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }
}
