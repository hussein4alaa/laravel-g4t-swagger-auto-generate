<?php

namespace G4T\Swagger\Controllers;

use G4T\Swagger\Support\CachedSpec;
use G4T\Swagger\Support\PartnerLinks;
use G4T\Swagger\Support\Settings;
use G4T\Swagger\Swagger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings dashboard: sign in with swagger.dashboard.username / password, then edit the
 * swagger.* settings and the order / visibility of operations (saved by {@see Settings}).
 */
class DashboardController
{
    private const SESSION_KEY = 'g4t_swagger_dashboard_user';

    private const MAX_ATTEMPTS = 5;

    /** @var array{title: string, description: string, tags: array<string, string>} */
    private array $defaults = ['title' => '', 'description' => '', 'tags' => []];

    public static function url(): string
    {
        return trim((string) config('swagger.dashboard.url', 'swagger/dashboard'), '/');
    }

    /** The dashboard exists only when it is enabled and both credentials are configured. */
    public static function available(): bool
    {
        return (bool) config('swagger.dashboard.enabled', true)
            && filled(config('swagger.dashboard.username'))
            && filled(config('swagger.dashboard.password'));
    }

    public static function signedIn(Request $request): bool
    {
        $user = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_string($user) && hash_equals((string) config('swagger.dashboard.username'), $user);
    }

    public function show(Request $request)
    {
        if (! self::signedIn($request)) {
            return response()->view('swagger::dashboard.login', ['action' => url(self::url().'/login')]);
        }

        return response()->view('swagger::dashboard.app', [
            'urls' => [
                'settings' => url(self::url().'/api/settings'),
                'logout' => url(self::url().'/logout'),
                'docs' => url(trim((string) config('swagger.url'), '/')),
            ],
            'user' => (string) config('swagger.dashboard.username'),
        ]);
    }

    public function login(Request $request)
    {
        $key = 'g4t-swagger-dashboard:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->loginFailed($request, "Too many attempts. Try again in {$seconds} seconds.", 429);
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        if (! $this->credentialsMatch($username, $password)) {
            RateLimiter::hit($key, 60);

            return $this->loginFailed($request, 'These credentials do not match the dashboard credentials.', 422);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, (string) config('swagger.dashboard.username'));

        return redirect()->to(url(self::url()));
    }

    public function logout(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();

        return redirect()->to(url(self::url()));
    }

    /**
     * Current values, what the dashboard overrides, and every documented operation.
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    public function save(Request $request): JsonResponse
    {
        $values = $this->validated($request);
        $layout = (array) $request->input('layout', []);
        $links = $request->has('links') ? $this->validatedLinks($request) : null;

        if (! Settings::save($values, $layout) || ($links !== null && ! Settings::saveLinks($links))) {
            return response()->json(['message' => 'Could not write '.Settings::path().'. Check that its directory is writable.'], 500);
        }

        return response()->json(['message' => 'Settings saved.'] + $this->state($request));
    }

    public function reset(Request $request): JsonResponse
    {
        if (! Settings::reset()) {
            return response()->json(['message' => 'Could not remove '.Settings::path().'.'], 500);
        }

        return response()->json(['message' => 'Dashboard changes removed; the config file applies again.'] + $this->state($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Request $request): array
    {
        $values = [];
        foreach (Settings::EDITABLE as $key) {
            $values[$key] = config('swagger.'.$key);
        }
        $values['password_set'] = filled($values['password']);
        unset($values['password']);

        return [
            'settings' => $values,
            'overridden' => array_keys(Settings::overrides()),
            'layout' => Settings::layout(),
            'operations' => $this->operations($request),
            // Generated (code) texts, shown as placeholders next to the dashboard's own.
            'defaults' => $this->defaults,
            'settings_file' => Settings::path(),
            // Partner links without their tokens: only a hash is stored.
            'links' => array_map(fn (array $link) => $link + ['expired' => PartnerLinks::expired($link)], Settings::links()),
            'cached_spec' => CachedSpec::read() !== null,
        ];
    }

    /**
     * Every documented operation (hidden ones included), in the current order.
     *
     * @return list<array{id: string, method: string, path: string, summary: string, tag: string, hidden: bool}>
     */
    private function operations(Request $request): array
    {
        $previous = $request->query('version');
        $request->query->set('version', 'all');
        try {
            $spec = json_decode(json_encode((new Swagger)->includingHidden()->withoutTextChanges()->swagger()), true);
        } finally {
            $previous === null ? $request->query->remove('version') : $request->query->set('version', $previous);
        }

        $this->defaults = [
            'title' => (string) ($spec['info']['title'] ?? ''),
            'description' => (string) ($spec['info']['description'] ?? ''),
            'tags' => array_column(array_map(fn (array $tag) => ['name' => $tag['name'], 'description' => (string) ($tag['description'] ?? '')], (array) ($spec['tags'] ?? [])), 'description', 'name'),
        ];
        $hidden = array_flip(Settings::layout()['hidden']);
        $operations = [];
        foreach ((array) ($spec['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                $id = (string) ($operation['operationId'] ?? strtolower($method).' '.$path);
                $operations[$id] = [
                    'id' => $id,
                    'method' => strtoupper($method),
                    'path' => (string) $path,
                    'summary' => (string) ($operation['summary'] ?? ''),
                    'description' => (string) ($operation['x-description'] ?? $operation['description'] ?? ''),
                    // Status set in code (->deprecated() / ->beta()), shown until the dashboard sets one.
                    'code_status' => array_filter(['status' => $operation['x-status'] ?? null, 'sunset' => $operation['x-sunset'] ?? null, 'replacement' => $operation['x-replaced-by'] ?? null, 'note' => $operation['x-note'] ?? null]),
                    'tag' => (string) ($operation['tags'][0] ?? 'default'),
                    'hidden' => isset($hidden[$id]),
                    'hidden_in_code' => ! empty($operation['x-hidden-in-code']),
                    'order' => (int) ($operation['x-order'] ?? PHP_INT_MAX),
                ];
            }
        }

        usort($operations, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(function (array $operation) {
            unset($operation['order']);

            return $operation;
        }, array_values($operations));
    }

    private function credentialsMatch(string $username, string $password): bool
    {
        $expectedUser = (string) config('swagger.dashboard.username');
        $expectedPassword = (string) config('swagger.dashboard.password');
        if ($expectedUser === '' || $expectedPassword === '') {
            return false;
        }

        $userMatches = hash_equals($expectedUser, $username);
        $isHash = (bool) preg_match('/^\$2[aby]\$|^\$argon2/', $expectedPassword);
        $passwordMatches = $isHash ? Hash::check($password, $expectedPassword) : hash_equals($expectedPassword, $password);

        return $userMatches && $passwordMatches;
    }

    private function loginFailed(Request $request, string $message, int $status)
    {
        return response()->view('swagger::dashboard.login', [
            'action' => url(self::url().'/login'),
            'error' => $message,
            'username' => (string) $request->input('username', ''),
        ], $status);
    }

    /**
     * Partner links to store: a new link sends its token (hashed here), a kept one its stored hash.
     *
     * @return list<array<string, mixed>>
     */
    private function validatedLinks(Request $request): array
    {
        $input = ['links' => (array) $request->input('links', [])];
        $stored = collect(Settings::links())->keyBy('hash');
        $validator = Validator::make($input, [
            'links' => ['array', 'max:100'],
            'links.*.id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'links.*.name' => ['required', 'string', 'max:60'],
            'links.*.tags' => ['array'],
            'links.*.tags.*' => ['string', 'max:200'],
            'links.*.operations' => ['array', 'max:500'],
            'links.*.operations.*' => ['string', 'max:200'],
            'links.*.expires' => ['nullable', 'date_format:Y-m-d'],
            'links.*.token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{24,128}$/'],
            'links.*.hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $validator->after(function ($validator) use ($input, $stored) {
            foreach ($input['links'] as $index => $link) {
                $hash = $link['hash'] ?? null;
                if (blank($link['token'] ?? null) && ($hash === null || ! $stored->has($hash))) {
                    $validator->errors()->add("links.{$index}.token", 'Create the link again: its token is missing.');
                }
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return array_map(function (array $link) use ($stored) {
            $hash = filled($link['token'] ?? null) ? hash('sha256', $link['token']) : $link['hash'];

            return [
                'id' => $link['id'],
                'name' => $link['name'],
                'hash' => $hash,
                'tags' => array_values((array) ($link['tags'] ?? [])),
                'operations' => array_values(array_unique((array) ($link['operations'] ?? []))),
                'expires' => $link['expires'] ?? null,
                'created' => $stored->get($hash)['created'] ?? now()->toDateString(),
            ];
        }, array_values($input['links']));
    }

    /**
     * @return array<string, mixed> editable swagger.* values ready to save
     */
    private function validated(Request $request): array
    {
        $input = (array) $request->input('settings', []);
        // One login (the config file's shape) or a list of them.
        if (isset($input['auto_login']) && is_array($input['auto_login']) && ! array_is_list($input['auto_login'])) {
            $input['auto_login'] = [$input['auto_login']];
        }
        $name = 'regex:/^[A-Za-z0-9_.-]+$/';
        $header = 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

        $validator = Validator::make($input, [
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'version' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:200'],
            'enable' => ['boolean'],
            'enable_auth' => ['boolean'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:200'],
            'ui' => [Rule::in(['g4t', 'swagger', 'scalar'])],
            'theme' => ['nullable', 'string', 'max:60', $name],
            'g4t' => ['array'],
            'g4t.mode' => [Rule::in(['light', 'dark', 'system'])],
            'g4t.density' => [Rule::in(['comfortable', 'compact'])],
            'g4t.getting_started' => ['boolean'],
            'scalar' => ['array'],
            'scalar.theme' => ['nullable', 'string', 'max:40', $name],
            'scalar.layout' => [Rule::in(['modern', 'classic'])],
            'versions' => ['array', 'min:1'],
            // Path segments are allowed: "driver/v1" lists /api/driver/v1/... routes.
            'versions.*' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)*$/', 'distinct'],
            'default' => ['nullable', 'string'],
            'servers' => ['array'],
            'servers.*.url' => ['required', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (! preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', (string) $value)) {
                    $fail('A server URL starts with http://, https:// or /.');
                }
            }],
            'servers.*.description' => ['nullable', 'string', 'max:200'],
            'security_schemes' => ['array'],
            'security_schemes.*.type' => ['required', Rule::in(['http', 'apiKey', 'oauth2', 'openIdConnect'])],
            'security_schemes.*.scheme' => ['nullable', 'string', 'max:30'],
            'security_schemes.*.in' => ['nullable', Rule::in(['header', 'query', 'cookie'])],
            'security_schemes.*.name' => ['nullable', 'string', 'max:100'],
            'security_schemes.*.bearerFormat' => ['nullable', 'string', 'max:50'],
            'auth_middlewares' => ['array', 'max:50'],
            'auth_middlewares.*' => ['required', 'string', 'max:200', 'distinct', 'regex:/^\S+$/'],
            'global_headers' => ['array'],
            'global_headers.*.name' => ['required', 'string', 'max:100', $header],
            'global_headers.*.description' => ['nullable', 'string', 'max:500'],
            'global_headers.*.required' => ['boolean'],
            'global_headers.*.example' => ['nullable', 'string', 'max:500'],
            'global_headers.*.default' => ['nullable', 'string', 'max:500'],
            'global_headers.*.enum' => ['nullable', 'array'],
            'global_headers.*.enum.*' => ['string', 'max:100'],
            'environments' => ['array', 'max:30'],
            'environments.*.name' => ['required', 'string', 'max:60', 'distinct'],
            'environments.*.base_url' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (filled($value) && ! preg_match('#^(https?://[^\s]+|/[^\s]*|\{\{[A-Za-z0-9_.-]+\}\}[^\s]*)$#i', (string) $value)) {
                    $fail('A base URL starts with http://, https://, / or a {{variable}}.');
                }
            }],
            'environments.*.variables' => ['array'],
            'environments.*.variables.*.key' => ['required', 'string', 'max:60', $name],
            'environments.*.variables.*.value' => ['nullable', 'string', 'max:2000'],
            'environments.*.headers' => ['array'],
            'environments.*.headers.*.key' => ['required', 'string', 'max:100', $header],
            'environments.*.headers.*.value' => ['nullable', 'string', 'max:2000'],
            'auto_login' => ['array', 'max:20'],
            'auto_login.*.name' => ['nullable', 'string', 'max:60', 'distinct'],
            'auto_login.*.url' => ['required', 'string', 'max:500'],
            'auto_login.*.method' => [Rule::in(['POST', 'PUT'])],
            'auto_login.*.fields' => ['array'],
            'auto_login.*.fields.*' => ['string', 'max:100'],
            'auto_login.*.token_path' => ['nullable', 'string', 'max:100'],
            'auto_login.*.security_scheme' => ['nullable', 'string', 'max:100'],
            'auto_login.*.prefill' => ['nullable', 'array'],
            'infer_response_examples' => ['boolean'],
            'infer_error_response_examples' => ['boolean'],
            'response_schemas' => ['boolean'],
            'unwrap_resource_collection_examples' => ['boolean'],
            'include_web_routes' => ['boolean'],
            'spatie_query_builder' => ['boolean'],
            'show_prefix' => ['array'],
            'show_prefix.*' => ['string', 'max:100'],
        ], [
            'versions.*.regex' => 'A version uses letters, digits, . _ - and "/" between parts, e.g. v1 or driver/v1.',
        ]);

        $validator->after(function ($validator) use ($input) {
            foreach (array_keys((array) ($input['security_schemes'] ?? [])) as $schemeName) {
                if (! preg_match('/^[A-Za-z0-9_.-]+$/', (string) $schemeName)) {
                    $validator->errors()->add('security_schemes', "\"{$schemeName}\": a scheme name uses letters, digits, . _ - only.");
                }
                $scheme = $input['security_schemes'][$schemeName];
                if (($scheme['type'] ?? null) === 'apiKey' && blank($scheme['name'] ?? null)) {
                    $validator->errors()->add("security_schemes.{$schemeName}.name", "\"{$schemeName}\": an API key needs the header or query parameter name.");
                }
                if (($scheme['type'] ?? null) === 'http' && blank($scheme['scheme'] ?? null)) {
                    $validator->errors()->add("security_schemes.{$schemeName}.scheme", "\"{$schemeName}\": choose bearer or basic.");
                }
            }
            if (isset($input['versions'], $input['default']) && ! in_array($input['default'], (array) $input['versions'], true)) {
                $validator->errors()->add('default', 'The default version must be one of the versions.');
            }
            foreach ((array) ($input['auto_login'] ?? []) as $index => $login) {
                foreach ((array) ($login['fields'] ?? []) as $field => $label) {
                    if (! is_string($field) || ! preg_match('/^[A-Za-z0-9_.-]+$/', $field)) {
                        $validator->errors()->add("auto_login.{$index}.fields", 'Login field names use letters, digits, . _ - only.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $values = array_intersect_key($input, array_flip(Settings::EDITABLE));
        // An empty password field keeps the current docs password.
        if (blank($values['password'] ?? null)) {
            unset($values['password']);
        }
        foreach (['security_schemes', 'auth_middlewares', 'global_headers', 'environments', 'servers', 'versions', 'show_prefix'] as $listKey) {
            if (array_key_exists($listKey, $values) && $values[$listKey] === null) {
                $values[$listKey] = [];
            }
        }
        if (isset($values['auth_middlewares'])) {
            $values['auth_middlewares'] = array_values($values['auth_middlewares']);
        }
        if (isset($values['global_headers'])) {
            $values['global_headers'] = array_values(array_map(fn (array $h) => array_filter($h, fn ($v) => $v !== null && $v !== '' && $v !== []), $values['global_headers']));
        }
        if (isset($values['security_schemes'])) {
            $values['security_schemes'] = array_map(fn (array $s) => array_filter($s, fn ($v) => $v !== null && $v !== ''), $values['security_schemes']);
        }
        if (isset($values['environments'])) {
            // Saved the way config/swagger.php writes them: name => value maps.
            $pairs = fn (array $list) => array_reduce($list, fn (array $map, array $pair) => $map + [$pair['key'] => (string) ($pair['value'] ?? '')], []);
            $values['environments'] = array_values(array_map(fn (array $env) => [
                'name' => $env['name'],
                'base_url' => (string) ($env['base_url'] ?? ''),
                'variables' => $pairs((array) ($env['variables'] ?? [])),
                'headers' => $pairs((array) ($env['headers'] ?? [])),
            ], $values['environments']));
        }
        if (isset($values['auto_login'])) {
            $values['auto_login'] = array_values(array_map(fn (array $login) => array_filter($login, fn ($v) => $v !== null && $v !== ''), $values['auto_login']));
        }

        return $values;
    }
}
