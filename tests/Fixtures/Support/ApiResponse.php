<?php

namespace G4T\Swagger\Tests\Fixtures\Support;

use Symfony\Component\HttpFoundation\Response;

class ApiResponse
{
    public static function responseSuccess($message, $data = null)
    {
        $response = [
            'status' => true,
            'message' => $message,
            'data' => $data,
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    public static function responseError($message, $errors = null, $code = Response::HTTP_BAD_REQUEST)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}
