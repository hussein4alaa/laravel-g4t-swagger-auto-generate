<?php

namespace G4T\Swagger\Controllers;

use App\Http\Controllers\Controller;
use G4T\Swagger\Swagger;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

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
        $versions = $this->reformatVersions();
        $themes = $this->getThemesList();
        $themes_path = url('g4t/swagger/themes');

        $stylesheet = config('swagger.stylesheet');
        return view('swagger::documentation', [
            'themes_path' => $themes_path,
            'response' => $response,
            'versions' => $versions,
            'stylesheet' => $stylesheet,
            'themes' => $themes
        ]);
    }

    private function reformatVersions()
    {
        $config = config('swagger');
        $versions = [];
        foreach ($config['versions'] as $version) {
            $versions[] = [
                'name' => $version,
                'url' => env('APP_URL')."/".$config["url"]."/json?version=$version"
            ];
        }
        $data['versions'] = $versions;
        $data['default'] = $config['default'];
        return $data;
    }

    private function getVersions()
    {
        $versions = config('swagger.versions');
        return $versions;
    }

    public function showJsonDocumentation()
    {
        $response = $this->getSwaggerData();
        return response()->json($response);
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


}