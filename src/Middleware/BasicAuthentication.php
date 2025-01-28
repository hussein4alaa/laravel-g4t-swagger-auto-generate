<?php

namespace G4T\Swagger\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BasicAuthentication
{

    private $username;
    private $password;
    private $ttl;
    private $enable_auth;
    public function __construct()
    {
        $this->username = config('swagger.username');
        $this->password = config('swagger.password');
        $this->ttl = config('swagger.sesson_ttl');
        $this->enable_auth = config('swagger.enable_auth');
    }

    public function handle(Request $request, Closure $next)
    {
        if(!$this->enable_auth) {
            return $next($request);
        }
        
        $lastActivity = cache()->get('auth_last_activity');
        if (cache()->has('auth_last_activity')) {
            $lastActivity = cache()->get('auth_last_activity');
            if (time() - $lastActivity > $this->ttl) {
                cache()->forget('auth_user');
                cache()->forget('auth_last_activity');
                throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Session expired.');
            }
        }

        if (!$request->hasHeader('Authorization')) {
            header('WWW-Authenticate: Basic realm="HiBit"');
            exit;
        }

        $credentials = base64_decode(substr($request->header('Authorization'), 6));
        [$username, $password] = explode(':', $credentials);

        if ($username !== $this->username || $password !== $this->password) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');
        }

        cache()->put('auth_user', $username);
        cache()->set('auth_last_activity', time());

        return $next($request);
    }
}
