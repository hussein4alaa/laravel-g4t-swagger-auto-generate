<?php

use G4T\Swagger\Tests\Fixtures\Support\Responder;
use Illuminate\Http\JsonResponse;

if (! function_exists('api_success')) {
    /**
     * A project's global response helper (app/helpers.php style).
     */
    function api_success(mixed $data = null, string $message = 'OK'): JsonResponse
    {
        return Responder::success($data, $message);
    }
}
