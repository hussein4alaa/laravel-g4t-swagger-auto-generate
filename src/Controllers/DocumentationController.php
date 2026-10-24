<?php

namespace G4T\Swagger\Controllers;

use G4T\Swagger\Support\CachedSpec;
use G4T\Swagger\Swagger;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use stdClass;

class DocumentationController
{
    /**
     * Freshly generated OpenAPI document.
     *
     * @return array<string, mixed>
     */
    public function getSwaggerData()
    {
        if (! config('swagger.enable')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return (new Swagger)->swagger();
    }

    public function showViewDocumentation()
    {
        if (! config('swagger.enable')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return view('swagger::documentation', [
            'themes_path' => url('g4t/swagger/themes'),
            'versions' => $this->reformatVersions(),
            'stylesheet' => config('swagger.stylesheet'),
            'themes' => $this->getThemesList(),
        ]);
    }

    private function reformatVersions()
    {
        $config = config('swagger');
        $versions = [];
        foreach ($config['versions'] as $version) {
            $versions[] = [
                'name' => $version,
                'url' => url($config['url'].'/json?version='.urlencode($version)),
            ];
        }

        return [
            'versions' => $versions,
            'default' => $config['default'],
        ];
    }

    public function showJsonDocumentation()
    {
        $data = $this->resolveSwaggerSpecification();
        if (request()->filled('version')) {
            $data = $this->filter($data);
        }

        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * OpenAPI document: static file when configured / cached file when present, else live from routes.
     * Cached files are decoded to objects so empty JSON objects (`{}`) stay objects.
     *
     * @return array<string, mixed>|stdClass
     */
    public function resolveSwaggerSpecification(): array|stdClass
    {
        if (! config('swagger.enable')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (config('swagger.load_from_json') || config('swagger.use_cached_spec_when_present', true)) {
            $cached = CachedSpec::read();
            if ($cached !== null) {
                $decoded = json_decode($cached);

                return $decoded instanceof stdClass ? $decoded : new stdClass;
            }
            if (config('swagger.load_from_json')) {
                return new stdClass;
            }
        }

        return $this->getSwaggerData();
    }

    /**
     * Keep only paths whose segments contain the requested version (`v1` matches `/api/v1/x`, not `/api/v10/x`)
     * and the tags those paths use.
     */
    private function filter(array|stdClass $data): stdClass
    {
        $data = json_decode(json_encode($data));
        $version = (string) request()->version;
        if ($version === 'all' || ! $data instanceof stdClass) {
            return $data instanceof stdClass ? $data : new stdClass;
        }

        // Legacy cached files nested paths/tags under components.
        $allPaths = $data->paths ?? $data->components->paths ?? new stdClass;
        if (isset($data->components)) {
            unset($data->components->paths, $data->components->tags);
        }

        $paths = new stdClass;
        $usedTags = [];
        foreach (get_object_vars($allPaths) as $uri => $operations) {
            if (! preg_match('#(^|/)'.preg_quote($version, '#').'(/|$)#', (string) $uri)) {
                continue;
            }
            $paths->{$uri} = $operations;
            foreach (get_object_vars($operations) as $operation) {
                foreach ($operation->tags ?? [] as $tag) {
                    $usedTags[$tag] = true;
                }
            }
        }

        $data->paths = $paths;
        $data->tags = array_values(array_filter(
            is_array($data->tags ?? null) ? $data->tags : [],
            fn ($tag) => is_object($tag) && isset($usedTags[$tag->name ?? '']),
        ));

        return $data;
    }

    public function getThemesList()
    {
        $themes = ['default'];
        $directory = public_path('g4t/swagger/themes');
        if (! is_dir($directory)) {
            return $themes;
        }

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() === 'css') {
                $themes[] = $file->getFilenameWithoutExtension();
            }
        }

        return $themes;
    }
}
