<?php

namespace G4T\Swagger\Controllers;

use G4T\Swagger\Helpers;
use G4T\Swagger\Swagger;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpParser\Node\Stmt\Return_;

class DocumentationController
{
    use Helpers;

    protected $remote_data;

    public function getSwaggerData()
    {
        $remote = app('remote_data');
        if (!$remote['enable']) {
            abort(Response::HTTP_FORBIDDEN);
        }
        $swager_json = new Swagger;
        $response = $swager_json->swagger();
        $response['servers'] = $remote['servers'];
        $response['components']->securitySchemes = $remote['security'];
        $response['info']['title'] = $remote['name'];
        return $response;
    }

    public function showViewDocumentation()
    {
        $response = $this->showJsonDocumentation();
        $versions = $this->reformatVersions();
        $themes = $this->getThemesList();
        $themes_path = url('g4t/swagger/themes');

        $stylesheet = config('swagger.stylesheet');
        $remote = app('remote_data');
        return view('swagger::documentation', [
            'themes_path' => $themes_path,
            'response' => $response,
            'versions' => $versions,
            'stylesheet' => $stylesheet,
            'themes' => $themes,
            'mode' => $remote['mode']
        ]);
    }

    private function reformatVersions()
    {
        $remote = app('remote_data');
        $versions = [];
        foreach ($remote['versions'] as $version) {
            $versions[] = [
                'name' => $version,
                'url' => url($remote["path"] . "/json?version=$version")
            ];
        }
        $data['versions'] = $versions;
        $data['default'] = $remote['default'];
        return $data;
    }



    // private function reformatVersions()
    // {
    //     $remote = app('remote_data');
    //     $versions = [];
    //     foreach ($remote['versions'] as $version) {
    //         $versions[] = [
    //             'name' => $version,
    //             'url' => url($remote["url"] . "/json?version=$version")
    //         ];
    //     }
    //     $data['versions'] = $versions;
    //     $data['default'] = $remote['default'];
    //     return $data;
    // }





    public function showJsonDocumentation()
    {
        $static_json = config('swagger.load_from_json');
        if ($static_json) {
            $filePath = public_path('doc.json');
            if (!file_exists($filePath)) {
                return [];
            }
            $jsonContent = file_get_contents($filePath);
            $data = json_decode($jsonContent, true);
            if (request()->filled('version')) {
                return $this->filter($data);
            }
            return $data;
        } else {
            $response = $this->getSwaggerData();
            return response()->json($response);
        }
    }

    public function filter($data)
    {
        $searchTerm = request()->version;
        if ($searchTerm == 'all') {
            return $data;
        }

        $paths = [];
        $tags = [];
        foreach ($data['components']['paths'] as $key => $path) {
            if (str_contains($key, $searchTerm)) {
                $paths[$key] = $data['components']['paths'][$key];
                foreach ($path as $path_key => $path_value) {
                    $tags[] = $path_value['tags'][0];
                }
            }
        }


        $data['components']['paths'] = $paths;
        $data['components']['tags'] = $tags;
        $data['tags'] = $tags;
        $data['paths'] = $paths;
        return $data;
    }

    public function getThemesList()
    {
        try {
            $directory = public_path('g4t/swagger/themes');
            $files = File::files($directory);
            $fileNamesWithoutCss = [];
            $fileNamesWithoutCss[] = 'default';
            foreach ($files as $file) {
                $fileName = pathinfo($file, PATHINFO_FILENAME);
                if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
                    $fileNameWithoutCss = str_replace('.css', '', $fileName);
                    $fileNamesWithoutCss[] = $fileNameWithoutCss;
                }
            }
            return $fileNamesWithoutCss;
        } catch (\Throwable $th) {
            $fileNamesWithoutCss[] = 'default';
            return $fileNamesWithoutCss;
        }
    }


    public function testing()
    {
        $swager_json = new Swagger;
        return $swager_json->generateSwaggerJsonResponse(true);
    }
}
