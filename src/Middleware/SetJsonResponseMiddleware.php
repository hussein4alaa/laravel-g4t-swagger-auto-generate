<?php

namespace G4T\Swagger\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use G4T\Swagger\Helpers;

class SetJsonResponseMiddleware
{
    use Helpers;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }



}