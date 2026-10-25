<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions;

use G4T\Swagger\Extensions\Example;
use G4T\Swagger\Extensions\Extension;
use G4T\Swagger\Extensions\RequestSpec;
use G4T\Swagger\Extensions\ReturnedValue;
use G4T\Swagger\Extensions\RouteInfo;
use G4T\Swagger\Tests\Fixtures\Extensions\Data\Data;
use Illuminate\Support\Str;

/** What a Spatie Laravel Data extension looks like. */
class DataExtension extends Extension
{
    public function request(RouteInfo $route): ?RequestSpec
    {
        $class = $route->parameterClass(Data::class);

        return $class !== null ? RequestSpec::fromClass($class, fn (string $name) => Str::snake($name)) : null;
    }

    public function response(ReturnedValue $value): ?Example
    {
        return $value->isStaticCallOn(Data::class, 'from')
            ? Example::fromClass($value->staticCall()['class'], fn (string $name) => Str::snake($name))
            : null;
    }
}
