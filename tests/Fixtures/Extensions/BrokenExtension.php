<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions;

use G4T\Swagger\Extensions\Example;
use G4T\Swagger\Extensions\Extension;
use G4T\Swagger\Extensions\RequestSpec;
use G4T\Swagger\Extensions\ReturnedValue;
use G4T\Swagger\Extensions\RouteInfo;
use RuntimeException;

class BrokenExtension extends Extension
{
    public function request(RouteInfo $route): ?RequestSpec
    {
        throw new RuntimeException('broken');
    }

    public function response(ReturnedValue $value): ?Example
    {
        throw new RuntimeException('broken');
    }

    public function operation(array $operation, RouteInfo $route): array
    {
        throw new RuntimeException('broken');
    }

    public function document(array $document): array
    {
        throw new RuntimeException('broken');
    }
}
