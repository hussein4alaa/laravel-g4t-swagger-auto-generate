<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions;

use G4T\Swagger\Extensions\Extension;
use G4T\Swagger\Extensions\RequestSpec;
use G4T\Swagger\Extensions\RouteInfo;

/** What a Laravel Actions extension looks like: the action class owns the rules. */
class ActionExtension extends Extension
{
    public function request(RouteInfo $route): ?RequestSpec
    {
        if ($route->action !== '__invoke' || $route->controller === null || ! method_exists($route->controller, 'rules')) {
            return null;
        }

        return RequestSpec::fromRules(app($route->controller)->rules());
    }
}
