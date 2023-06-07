<?php

namespace G4T\Swagger\Sections;

use G4T\Swagger\Responses\DeleteResponse;
use G4T\Swagger\Responses\GetResponse;
use G4T\Swagger\Responses\PostResponse;
use G4T\Swagger\Responses\PutResponse;

trait Paths {


    public function formatPaths($routes)
    {
        $groupedData = [];
        foreach ($routes as $route) {
            $uri = $route['uri'];
            if (!isset($groupedData[$uri])) {
                $groupedData[$uri] = [];
            }
            if ($route['method'] == 'POST') {
                $groupedData[$uri]['post'] = PostResponse::index($route);
            } else if ($route['method'] == 'GET|HEAD') {
                $groupedData[$uri]['get'] = GetResponse::index($route);
            } else if ($route['method'] == 'PUT') {
                $groupedData[$uri]['put']  = PutResponse::index($route);
            } else if ($route['method'] == 'DELETE') {
                $groupedData[$uri]['delete'] = DeleteResponse::index($route);
            }
        }
        return $groupedData;
    }
    
}