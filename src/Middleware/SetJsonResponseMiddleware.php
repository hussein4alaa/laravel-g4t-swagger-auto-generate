<?php

namespace G4T\Swagger\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetJsonResponseMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        
        $response = $next($request);
        $response->header('Content-Type', 'application/json');
        
        return $response;
    }
}
