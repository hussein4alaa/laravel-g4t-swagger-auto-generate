<?php

namespace G4T\Swagger\Sections;

use G4T\Swagger\Responses\DeleteResponse;
use G4T\Swagger\Responses\GetResponse;
use G4T\Swagger\Responses\PatchResponse;
use G4T\Swagger\Responses\PostResponse;
use G4T\Swagger\Responses\PutResponse;

trait Paths {


    public function formatPaths(array $routes) : array
    {
        $groupedData = [];
        foreach ($routes as $route) {
            $post_with_method = false;
            $validations_string = json_encode($route['validations']);
            if (str_contains($validations_string, 'image') || str_contains($validations_string, 'file') || str_contains($validations_string, 'mimes')) { 
                $post_with_method = true;
            }
            $uri = $route['uri'];
            if (!isset($groupedData[$uri])) {
                $groupedData[$uri] = [];
            }
            if($post_with_method) {
                $groupedData[$uri]['post'] = PostResponse::index($route);
            } else if ($route['method'] == 'POST') {
                $groupedData[$uri]['post'] = PostResponse::index($route);
            } else if ($route['method'] == 'GET|HEAD' OR $route['method'] == 'GET') {
                $groupedData[$uri]['get'] = GetResponse::index($route);
            } else if ($route['method'] == 'PUT|PATCH' OR $route['method'] == 'PUT') {
                $groupedData[$uri]['put']  = PutResponse::index($route);
            } else if ($route['method'] == 'PATCH') {
                $groupedData[$uri]['patch']  = PatchResponse::index($route);
            } else if ($route['method'] == 'DELETE') {
                $groupedData[$uri]['delete'] = DeleteResponse::index($route);
            }
        }
        return $groupedData;
    }
    
}