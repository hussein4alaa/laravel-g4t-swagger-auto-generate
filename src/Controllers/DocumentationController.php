<?php

namespace G4T\Swagger\Controllers;

use G4T\Swagger\Support\CachedSpec;
use G4T\Swagger\Support\PartnerLinks;
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
        $this->abortUnlessEnabled();

        return (new Swagger)->swagger();
    }

    /**
     * The docs are on, or the request carries a partner link (which works even with the docs turned off).
     */
    private function abortUnlessEnabled(): void
    {
        if (! config('swagger.enable') && PartnerLinks::current(request()) === null) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    public function showViewDocumentation()
    {
        $this->abortUnlessEnabled();
        $partner = PartnerLinks::current(request());

        $logins = $this->autoLogins();
        $shared = [
            'versions' => $this->reformatVersions(),
            // Swagger UI and Scalar have one Login button: the first login.
            'autoLogin' => $logins[0] ?? null,
            'autoLogins' => $logins,
        ];

        // Partner links only open the g4t UI (the other UIs would drop the key).
        $ui = $partner !== null ? 'g4t' : $this->selectedUi();
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
                'environments' => $this->sharedEnvironments(),
                'partner' => $partner === null ? null : ['name' => $partner['name'], 'key' => $partner['token'], 'sections' => $partner['tags'], 'endpoints' => count($partner['operations']), 'expires' => $partner['expires']],
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
     * @return array{mode: string, density: string, getting_started: bool}
     */
    private function g4tSettings(): array
    {
        $settings = (array) config('swagger.g4t', []);
        $mode = (string) ($settings['mode'] ?? 'light');
        $density = (string) ($settings['density'] ?? 'comfortable');

        return [
            'mode' => in_array($mode, ['light', 'dark', 'system'], true) ? $mode : 'light',
            'density' => in_array($density, ['comfortable', 'compact'], true) ? $density : 'comfortable',
            'getting_started' => (bool) ($settings['getting_started'] ?? true),
        ];
    }

    /**
     * Environments from swagger.environments, in the shape the g4t UI keeps its own.
     *
     * @return list<array{id: string, name: string, baseUrl: string, vars: list<array{key: string, value: string}>, headers: list<array{key: string, value: string}>}>
     */
    private function sharedEnvironments(): array
    {
        $pairs = function ($list): array {
            $pairs = [];
            foreach ((array) $list as $key => $value) {
                // Both ["name" => "value"] and [["key" => "name", "value" => "value"]] are accepted.
                if (is_array($value)) {
                    [$key, $value] = [$value['key'] ?? null, $value['value'] ?? ''];
                }
                if (is_string($key) && $key !== '' && (is_scalar($value) || $value === null)) {
                    $pairs[] = ['key' => $key, 'value' => (string) $value];
                }
            }

            return $pairs;
        };

        $environments = [];
        foreach ((array) config('swagger.environments', []) as $environment) {
            if (! is_array($environment) || blank($environment['name'] ?? null)) {
                continue;
            }
            $name = (string) $environment['name'];
            $environments[] = [
                // Stable across reorders, so a reader's active environment survives them.
                'id' => 'shared:'.substr(md5($name), 0, 12),
                'name' => $name,
                'baseUrl' => (string) ($environment['base_url'] ?? ''),
                'vars' => $pairs($environment['variables'] ?? []),
                'headers' => $pairs($environment['headers'] ?? []),
            ];
        }

        return $environments;
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
     * Every configured login: swagger.auto_login is one login or a list of them (customer, admin, …).
     * Logins without a URL are left out.
     *
     * @return list<array{name: string, url: string, method: string, fields: array<string, string>, tokenPath: ?string, scheme: string, prefill: array<string, string>}>
     */
    private function autoLogins(): array
    {
        $config = (array) config('swagger.auto_login', []);
        $logins = [];
        foreach (array_is_list($config) ? $config : [$config] as $index => $login) {
            $settings = is_array($login) ? $this->autoLoginSettings($login, $index) : null;
            if ($settings !== null) {
                $logins[] = $settings;
            }
        }

        return $logins;
    }

    /**
     * Settings for one Login button, or null when it has no URL.
     *
     * @param  array<string, mixed>  $login
     * @return array{name: string, url: string, method: string, fields: array<string, string>, tokenPath: ?string, scheme: string, prefill: array<string, string>}|null
     */
    private function autoLoginSettings(array $login, int $index): ?array
    {
        if (blank($login['url'] ?? null)) {
            return null;
        }

        $url = (string) $login['url'];
        $schemes = array_keys((array) config('swagger.security_schemes', []));
        $scheme = (string) ($login['security_scheme'] ?? '');
        $fields = array_filter((array) ($login['fields'] ?? []), 'is_string');

        return [
            'name' => filled($login['name'] ?? null) ? (string) $login['name'] : 'Login '.($index + 1),
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
                'url' => url($config['url'].'/json?version='.urlencode($version).$this->keyQuery()),
            ];
        }

        return [
            'versions' => $versions,
            'default' => $config['default'],
        ];
    }

    /** "&key=…" for the URLs of a partner page. */
    private function keyQuery(): string
    {
        $partner = PartnerLinks::current(request());

        return $partner === null ? '' : '&'.PartnerLinks::PARAM.'='.urlencode($partner['token']);
    }

    public function showJsonDocumentation()
    {
        $partner = PartnerLinks::current(request());
        // A link that picks single endpoints may pick hidden ones: built with them, then narrowed to the link.
        $data = $partner !== null && $partner['operations'] !== []
            ? (new Swagger)->includingHidden()->swagger()
            : $this->resolveSwaggerSpecification();
        if (request()->filled('version')) {
            $data = $this->filter($data);
        }
        if ($partner !== null) {
            $data = PartnerLinks::scope(json_decode(json_encode($data)), $partner['tags'], $partner['operations']);
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
        $this->abortUnlessEnabled();

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
