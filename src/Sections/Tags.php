<?php

namespace G4T\Swagger\Sections;

trait Tags {

    public function getTags($names)
    {
        $tags = [];
        $list_of_names = array_unique($names);
        foreach ($list_of_names as $name) {
            $tags[] = [
                'name' => $name,
                'description' => "Everything about your $name"
            ];
        }
        return $tags;
    }


}