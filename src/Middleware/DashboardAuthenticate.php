<?php

namespace G4T\Swagger\Middleware;

use Closure;
use G4T\Swagger\Controllers\DashboardController;
use Illuminate\Http\Request;

/**
 * Lets only a signed-in dashboard session through. Changing the configured username signs
 * everyone out, because the session stores the name it signed in with.
 */
class DashboardAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        if (DashboardController::signedIn($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your dashboard session has ended. Sign in again.'], 401);
        }

        return redirect()->to(url(DashboardController::url()));
    }
}
