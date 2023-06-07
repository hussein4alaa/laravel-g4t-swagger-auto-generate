<?php

namespace G4T\Swagger\Controllers;

use App\Http\Controllers\Controller;
use G4T\Swagger\Swagger;
use Illuminate\Http\Response;

class DocumentationController extends Controller
{
    
    public function getSwaggerData()
    {
        $enable = config('swagger.enable');
        if(!$enable) {
            abort(Response::HTTP_FORBIDDEN);
        }
        $swager_json = new Swagger;
        $response = $swager_json->swagger();
        return $response;
    }

    public function showViewDocumentation()
    {
        $response = $this->getSwaggerData();
        return view('swagger::documentation', ['response' => $response]);
    }

    public function showJsonDocumentation()
    {
        $response = $this->getSwaggerData();
        return response()->json($response);
    }


}