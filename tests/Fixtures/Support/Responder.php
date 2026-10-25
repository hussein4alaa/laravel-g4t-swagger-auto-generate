<?php

namespace G4T\Swagger\Tests\Fixtures\Support;

use Illuminate\Http\JsonResponse;

/**
 * A project's own response wrapper, the way many APIs return every response.
 */
class Responder
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(mixed $data = null): JsonResponse
    {
        return self::success($data, 'Created', 201);
    }

    public static function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
