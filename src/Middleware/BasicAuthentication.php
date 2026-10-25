<?php

namespace G4T\Swagger\Middleware;

use Closure;
use G4T\Swagger\Support\PartnerLinks;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic Auth for the documentation routes.
 *
 * Stateless: every request must carry valid credentials. When the docs routes run with a session
 * (`swagger.middleware` includes `web`), `session_ttl` additionally expires idle sessions per browser.
 */
class BasicAuthentication
{
    private const SESSION_KEY = 'g4t_swagger_last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        // A link that does not work says why, instead of a password prompt (or the public docs).
        $problem = PartnerLinks::problem($request);
        if ($problem !== null) {
            return $this->brokenLink($request, $problem);
        }

        // A partner link is its own credential.
        if (! config('swagger.enable_auth') || PartnerLinks::current($request) !== null) {
            return $next($request);
        }

        $expectedUsername = (string) config('swagger.username');
        $expectedPassword = (string) config('swagger.password');
        $credentials = $this->credentials($request);

        // An empty configured password never authenticates.
        if ($credentials === null || $expectedPassword === '') {
            return $this->unauthorized();
        }

        [$username, $password] = $credentials;
        $valid = hash_equals($expectedUsername, $username) & hash_equals($expectedPassword, $password);
        if (! $valid) {
            return $this->unauthorized();
        }

        if ($request->hasSession()) {
            $session = $request->session();
            $ttl = (int) (config('swagger.session_ttl') ?? config('swagger.sesson_ttl') ?? 0);
            $lastActivity = $session->get(self::SESSION_KEY);
            if ($ttl > 0 && is_int($lastActivity) && time() - $lastActivity > $ttl) {
                $session->forget(self::SESSION_KEY);

                return $this->unauthorized('Session expired.');
            }
            $session->put(self::SESSION_KEY, time());
        }

        return $next($request);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function credentials(Request $request): ?array
    {
        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($header), $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return [$username, $password];
    }

    /**
     * 410 for an expired link, 403 for one that is not valid: a page in the browser, JSON for the document URL.
     *
     * @param  array{reason: string, expires: ?string}  $problem
     */
    private function brokenLink(Request $request, array $problem): Response
    {
        $expired = $problem['reason'] === 'expired';
        $status = $expired ? Response::HTTP_GONE : Response::HTTP_FORBIDDEN;
        $headers = ['Cache-Control' => 'no-store'];

        if ($request->expectsJson() || $request->routeIs('swagger.json')) {
            return response()->json([
                'message' => $expired ? 'This documentation link expired on '.$problem['expires'].'.' : 'This documentation link is not valid.',
                'reason' => $problem['reason'],
            ], $status, $headers);
        }

        return response()->view('swagger::partner-link', ['expired' => $expired, 'expires' => $problem['expires']], $status, $headers);
    }

    private function unauthorized(string $message = 'Unauthorized.'): Response
    {
        return response($message, Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="API Documentation", charset="UTF-8"',
        ]);
    }
}
