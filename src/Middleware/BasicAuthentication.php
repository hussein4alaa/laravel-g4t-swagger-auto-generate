<?php

namespace G4T\Swagger\Middleware;

use Closure;
use G4T\Swagger\Exceptions\UnauthorizedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BasicAuthentication
{
    private $type;
    private $email;
    private $password;
    private $ttl;
    private $enable_auth;

    public function __construct()
    {
        $this->type = config('swagger.auth.type');
        $this->email = config('swagger.auth.email');
        $this->password = config('swagger.auth.password');
        $this->ttl = config('swagger.auth.sesson_ttl');
        $this->enable_auth = config('swagger.auth.enable');
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->enable_auth) {
            return $next($request);
        }

        if (!$request->hasHeader('Authorization')) {
            header('WWW-Authenticate: Basic realm="HiBit"');
            exit;
        }

        [$email, $password] = $this->getCredentials($request);

        $this->authLastActivity($email);

        if ($this->type == 'remote') {
            $this->remoteAuth($email, $password);
        } else if ($this->type == 'local') {
            $this->localAuth($email, $password);
        } else {
            throw new UnauthorizedException("Incorrect auth type", Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        cache()->put("auth_user_{$email}", $email, $this->ttl);
        cache()->set("auth_last_activity_{$email}", time(), $this->ttl);

        return $next($request);
    }

    private function getCredentials($request)
    {
        $credentials = base64_decode(substr($request->header('Authorization'), 6));
        return explode(':', $credentials);
    }

    private function authLastActivity($email)
    {
        $last_activity_key = "auth_last_activity_{$email}";
        if (cache()->has($last_activity_key)) {
            $last_activity = cache()->get($last_activity_key);
            if (time() - $last_activity > $this->ttl) {
                cache()->forget("auth_user_{$email}");
                cache()->forget($last_activity_key);
                throw new UnauthorizedException("Session expired", Response::HTTP_UNAUTHORIZED);
            }
        }
    }

    private function remoteAuth($email, $password)
    {
            $response = Http::post('http://swagger-domain/api/auth', [
                'email' => $email,
                'password' => $password,
                'app_key' => env('APP_KEY')
            ]);
            if ($response->failed()) {
                $message = $response->json();
                throw new UnauthorizedException($message['message'], Response::HTTP_UNAUTHORIZED);
            }
            return true;
    }

    private function localAuth($email, $password)
    {
        if ($email !== $this->email || $password !== $this->password) {
            throw new UnauthorizedException("Invalid credentials.", Response::HTTP_UNAUTHORIZED);
        }
    }
}
