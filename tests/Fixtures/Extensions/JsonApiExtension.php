<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions;

use G4T\Swagger\Extensions\Extension;
use G4T\Swagger\Extensions\RouteInfo;

/** What a JSON:API extension looks like: its routes speak application/vnd.api+json. */
class JsonApiExtension extends Extension
{
    public function operation(array $operation, RouteInfo $route): array
    {
        if (! str_starts_with($route->uri, '/api/v1/data')) {
            return $operation;
        }
        foreach ($operation['responses'] as $status => $response) {
            if (isset($response['content']['application/json'])) {
                $operation['responses'][$status]['content'] = ['application/vnd.api+json' => $response['content']['application/json']];
            }
        }
        $operation['x-json-api'] = true;

        return $operation;
    }

    public function document(array $document): array
    {
        $document['info']['x-json-api-version'] = '1.1';

        return $document;
    }
}
