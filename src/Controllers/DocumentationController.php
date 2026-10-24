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

        $shared = [
            'versions' => $this->reformatVersions(),
            'autoLogin' => $this->autoLoginSettings(),
        ];

        $ui = $this->selectedUi();
        if ($ui === 'scalar') {
            return view('swagger::scalar', $shared + [
                'scalar' => $this->scalarSettings(),
            ]);
        }
        if ($ui === 'g4t') {
            return view('swagger::g4t', $shared + [
                'themes' => $this->getThemesList(),
                'defaultTheme' => (string) config('swagger.theme', 'default'),
                'g4t' => $this->g4tSettings(),
            ]);
        }

        return view('swagger::documentation', $shared + [
            'themes_path' => url('g4t/swagger/themes'),
            'themes' => $this->getThemesList(),
            'defaultTheme' => (string) config('swagger.theme', 'default'),
        ]);
    }

    /**
     * "g4t", "swagger" or "scalar": the ?ui= query wins over the config for one visit.
     */
    private function selectedUi(): string
    {
        $supported = ['g4t', 'swagger', 'scalar'];
        $requested = strtolower((string) request()->query('ui', ''));
        if (in_array($requested, $supported, true)) {
            return $requested;
        }

        $configured = strtolower((string) config('swagger.ui', 'g4t'));

        return in_array($configured, $supported, true) ? $configured : 'g4t';
    }

    /**
     * @return array{mode: string, density: string}
     */
    private function g4tSettings(): array
    {
        $settings = (array) config('swagger.g4t', []);
        $mode = (string) ($settings['mode'] ?? 'light');
        $density = (string) ($settings['density'] ?? 'comfortable');

        return [
            'mode' => in_array($mode, ['light', 'dark', 'system'], true) ? $mode : 'light',
            'density' => in_array($density, ['comfortable', 'compact'], true) ? $density : 'comfortable',
        ];
    }

    /**
     * @return array{version: string, theme: string, layout: string, darkMode: ?bool, locale: ?string, direction: string, hideModels: bool, defaultOpenAllTags: bool}
     */
    private function scalarSettings(): array
    {
        $scalar = (array) config('swagger.scalar', []);
        $version = (string) ($scalar['version'] ?? '1.73.0');

        return [
            // Only plain semver can reach the CDN URL.
            'version' => preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : '1.73.0',
            'theme' => (string) ($scalar['theme'] ?? 'purple'),
            'layout' => ($scalar['layout'] ?? 'modern') === 'classic' ? 'classic' : 'modern',
            'darkMode' => isset($scalar['dark_mode']) ? (bool) $scalar['dark_mode'] : null,
            'locale' => filled($scalar['locale'] ?? null) ? (string) $scalar['locale'] : null,
            'direction' => in_array($scalar['direction'] ?? 'auto', ['auto', 'ltr', 'rtl'], true) ? $scalar['direction'] : 'auto',
            'hideModels' => (bool) ($scalar['hide_models'] ?? false),
            'defaultOpenAllTags' => (bool) ($scalar['default_open_all_tags'] ?? false),
        ];
    }

    /**
     * Settings for the Login button, or null when no login URL is configured.
     *
     * @return array{url: string, method: string, fields: array<string, string>, tokenPath: ?string, scheme: string, prefill: array<string, string>}|null
     */
    private function autoLoginSettings(): ?array
    {
        $login = (array) config('swagger.auto_login', []);
        if (blank($login['url'] ?? null)) {
            return null;
        }

        $url = (string) $login['url'];
        $schemes = array_keys((array) config('swagger.security_schemes', []));
        $scheme = (string) ($login['security_scheme'] ?? '');
        $fields = array_filter((array) ($login['fields'] ?? []), 'is_string');

        return [
            'url' => preg_match('#^https?://#i', $url) ? $url : url($url),
            'method' => strtoupper((string) ($login['method'] ?? 'POST')),
            'fields' => $fields !== [] ? $fields : ['email' => 'Email', 'password' => 'Password'],
            'tokenPath' => filled($login['token_path'] ?? null) ? (string) $login['token_path'] : null,
            'scheme' => in_array($scheme, $schemes, true) ? $scheme : (string) ($schemes[0] ?? 'bearerAuth'),
            // Never ship pre-filled credentials outside the local environment.
            'prefill' => app()->isLocal() ? array_filter((array) ($login['prefill'] ?? []), 'is_string') : [],
        ];
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
