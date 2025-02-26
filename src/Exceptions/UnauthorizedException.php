<?php

namespace G4T\Swagger\Exceptions;

use Exception;

class UnauthorizedException extends Exception
{
    public function __construct($message = "Unauthorized access", $code = 0)
    {
        parent::__construct($message, $code);
    }

    public function render($request)
    {
        return response()->view('swagger::unauthorized', ['message' => $this->getMessage(), 'code' => $this->getCode()], $this->getCode());
    }
}
