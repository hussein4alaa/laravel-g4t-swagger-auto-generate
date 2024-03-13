<?php

namespace G4T\Swagger\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use G4T\Swagger\Helpers;

class SetJsonResponseMiddleware
{
    use Helpers;

    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        $response->header('Content-Type', 'application/json');
        if($request->header('is-swagger')) {
            $enable_response_schema = config('swagger.enable_response_schema');
            $stop_saving_response = config('swagger.stop_saving_response');
            if($enable_response_schema && !$stop_saving_response) {
                $this->createSchemaExampleDir($response, $request);
            }
        }
        return $response;
    }


    public function createSchemaExampleDir($response, $request)
    {
        $status_text = $response->statusText();
        $status_code = $response->getStatusCode();
        $response = $response->getData();
        $originalPath = $request->route()->uri();
        $correct_path = '-'.str_replace(['/', '{', '}', '?'], '-', $originalPath);
        $dir = $this->getDir();
        $directoryPath = storage_path("swagger/$dir/$correct_path");
        if (!is_dir($directoryPath)) {
            mkdir($directoryPath, 0777, true);
        }
    
        $jsonData = [
            'status_text' => $status_text,
            'response' => $response,
        ];
    
        $jsonContent = json_encode($jsonData, JSON_PRETTY_PRINT);
        $jsonFilePath = $directoryPath . "/$status_code.json";
        file_put_contents($jsonFilePath, $jsonContent);
    }


    public function getDir()
    {
        $currentRouteAction = Route::currentRouteAction();
        return $this->getControllerName($currentRouteAction);
    }
}
