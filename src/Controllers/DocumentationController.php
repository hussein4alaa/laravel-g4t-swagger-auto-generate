<?php

namespace G4T\Swagger\Controllers;

use G4T\Swagger\Swagger;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class DocumentationController
{

    public function getSwaggerData()
    {
        $enable = config('swagger.enable');
        if (!$enable) {
            abort(Response::HTTP_FORBIDDEN);
        }
        $swager_json = new Swagger;
        $response = $swager_json->swagger();
        return $response;
    }

    public function showViewDocumentation()
    {
        $payload = $this->resolveSwaggerSpecification();
        $versions = $this->reformatVersions();
        $themes = $this->getThemesList();
        $themes_path = url('g4t/swagger/themes');

        $stylesheet = config('swagger.stylesheet');
        return view('swagger::documentation', [
            'themes_path' => $themes_path,
            'response' => $payload,
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
                'url' => url($config["url"] . "/json?version=$version")
            ];
        }
        $data['versions'] = $versions;
        $data['default'] = $config['default'];
        return $data;
    }


    public function showJsonDocumentation()
    {
        $data = $this->resolveSwaggerSpecification();
        if (request()->filled('version')) {
            $data = $this->filter($data);
        }

        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * OpenAPI document: static file when configured / cached file when present, else live from routes.
     *
     * @return array<string, mixed>
     */
    public function resolveSwaggerSpecification(): array
    {
        if (! config('swagger.enable', true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (config('swagger.load_from_json')) {
            $cached = $this->tryReadCachedSwaggerJson();

            return $cached ?? [];
        }

        if (config('swagger.use_cached_spec_when_present', true)) {
            $cached = $this->tryReadCachedSwaggerJson();
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->getSwaggerData();
    }

    private function swaggerPublicSpecPath(): string
    {
        return public_path(config('swagger.cached_spec_path', 'doc.json'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryReadCachedSwaggerJson(): ?array
    {
        $path = $this->swaggerPublicSpecPath();
        if (! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return null;
        }

        return $data;
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
}
