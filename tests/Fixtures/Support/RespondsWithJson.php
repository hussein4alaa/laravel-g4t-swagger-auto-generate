<?php

namespace G4T\Swagger\Tests\Fixtures\Support;

use Illuminate\Http\JsonResponse;

trait RespondsWithJson
{
    protected function successResponse(mixed $data, string $message = 'OK', int $code = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'result' => $data], $code);
    }
}
