<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V2;

class ProductController
{
    public function index()
    {
        return response()->json(['version' => 'v2', 'products' => []]);
    }
}
