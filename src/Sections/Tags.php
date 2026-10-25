<?php

namespace G4T\Swagger\Sections;

trait Tags
{
    /**
     * @param  array<string, array{name: string, description: ?string}>  $controllers
     * @return list<array<string, string>>
     */
    public function getTags($controllers)
    {
        $tags = [];
        foreach ($controllers as $controller) {
            $tag = ['name' => $controller['name']];
            if (! empty($controller['description'])) {
                $tag['description'] = $controller['description'];
            }
            if (! empty($controller['x_translations'])) {
                $tag['x-translations'] = $controller['x_translations'];
            }
            $tags[] = $tag;
        }

        return $tags;
    }
}
