<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V1;

class ProductController
{
    public function index()
    {
        return response()->json(['version' => 'v1', 'products' => []]);
    }
}
