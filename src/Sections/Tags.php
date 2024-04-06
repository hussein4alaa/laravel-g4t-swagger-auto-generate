<?php

namespace G4T\Swagger\Sections;

trait Tags {

    public function getTags($controllers)
    {
        $tags = [];
        foreach ($controllers as $controller) {
            $tags[] = [
                'name' => $controller['name'],
                'description' => $controller['description']
            ];
        }
        return $tags;
    }


}