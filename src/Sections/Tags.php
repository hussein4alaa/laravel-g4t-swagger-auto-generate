<?php

namespace G4T\Swagger\Sections;

trait Tags
{

    /**
     * Get tags of the sections
     *
     * @param array $names
     * @return array<array>
     */
    public function getTags(array $names)
    {
        return array_map(function ($name) {
            return [
                'name' => $name,
                'description' => "Everything about your $name"
            ];
        }, array_values(array_unique($names)));
    }
}
