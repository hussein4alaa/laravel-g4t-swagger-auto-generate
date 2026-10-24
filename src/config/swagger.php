<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Title
    |--------------------------------------------------------------------------
    |
    | The title of your API documentation.
    |
    */
    "title" => env("SWAGGER_TITLE", "Api Documentation"),

    /*
    |--------------------------------------------------------------------------
    | API Description
    |--------------------------------------------------------------------------
    |
    | The description of your API.
    |
    */
    "description" => env("SWAGGER_DESCRIPTION", "Laravel autogenerate swagger"),

    /*
    |--------------------------------------------------------------------------
    | API Email
    |--------------------------------------------------------------------------
    |
    | The email associated with your API documentation.
    |
    */
    "email" => env("SWAGGER_EMAIL", "hussein4alaa@gmail.com"),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The version of your API.
    |
    */
    "version" => env("SWAGGER_VERSION", "3.0.7"),

    /*
    |--------------------------------------------------------------------------
    | Documentation Auth
    |--------------------------------------------------------------------------
    |
    | This options to enable documentation auth
    |
    */
    "enable_auth" => env("SWAGGER_AUTH_ENABLED", false),
    "username" => env("SWAGGER_USERNAME", "admin"),
    // Set SWAGGER_PASSWORD when enable_auth is on: an empty password never authenticates.
    "password" => env("SWAGGER_PASSWORD", ""),
    // Idle timeout (seconds), only applied when the docs routes have a session (see "middleware").
    // The old misspelled "sesson_ttl" key is still read when this one is missing.
    "session_ttl" => env("SWAGGER_SESSION_TTL", 100000),

    /*
    |--------------------------------------------------------------------------
    | Documentation Route Middleware
    |--------------------------------------------------------------------------
    |
    | Extra middleware for the documentation routes, e.g. ["web"] for sessions
    | or your own gate. Basic auth (above) is always appended.
    |
    */
    "middleware" => [],

    /*
    |--------------------------------------------------------------------------
    | Enable Response Schema
    |--------------------------------------------------------------------------
    |
    | Whether to enable response schema or not.
    |
    */
    "enable_response_schema" => true,

    /*
    |--------------------------------------------------------------------------
    | Infer response examples from controllers
    |--------------------------------------------------------------------------
    |
    | When true, success response examples are derived from:
    | - return response()->json([ ... ]) when the payload is a literal array
    | - return new SomeResource(...) or SomeResource::collection(...) (JsonResource)
    | Manual JSON files under storage/swagger/... still override when present.
    |
    */
    "infer_response_examples" => true,

    /*
    |--------------------------------------------------------------------------
    | Infer error / validation response examples
    |--------------------------------------------------------------------------
    |
    | When true, adds examples for:
    | - return response()->json([...], 4xx/5xx) with a literal array payload
    | - abort(4xx, 'message')
    | - FormRequest rules: 422 with Laravel-style { message, errors } (when rules exist)
    | storage/swagger/... JSON files still override per status when present.
    |
    */
    "infer_error_response_examples" => true,

    /*
    |--------------------------------------------------------------------------
    | Response schemas
    |--------------------------------------------------------------------------
    |
    | When true, every documented JSON response gets a schema next to its
    | example. JsonResources and Eloquent models become reusable components
    | (#/components/schemas/Product, ...) referenced with $ref, so client
    | generators (openapi-generator, orval, ...) produce typed models.
    |
    */
    "response_schemas" => env("SWAGGER_RESPONSE_SCHEMAS", true),

    /*
    |--------------------------------------------------------------------------
    | Unwrap resource collection examples (non-paginated)
    |--------------------------------------------------------------------------
    |
    | When true, inferred examples for return SomeResource::collection(...) (without paginate)
    | use a top-level JSON array [ { ... } ] instead of { "data": [ { ... } ] }.
    | Paginated collections still use data / links / meta.
    |
    */
    "unwrap_resource_collection_examples" => true,

    /*
    |--------------------------------------------------------------------------
    | Omit default 404 for show (GET)
    |--------------------------------------------------------------------------
    |
    | When true, GET routes whose controller action is `show` will not list the default
    | 404 Not Found response unless a real example exists (inferred from the controller
    | or storage/swagger/.../*.json).
    |
    */
    "omit_default_404_for_show" => true,

    "suggestions_select_input" => false,

    "load_from_json" => false,

    /*
    |--------------------------------------------------------------------------
    | Cached OpenAPI JSON (faster Swagger UI)
    |--------------------------------------------------------------------------
    |
    | When load_from_json is false and use_cached_spec_when_present is true, the JSON
    | endpoint serves the cached file (see cached_spec_location) if it exists (e.g. after
    | `php artisan swagger:cache` or `php artisan make:swagger`). Otherwise it builds
    | the spec from routes on each request.
    |
    | When load_from_json is true, only the file is used (same as before).
    |
    */
    "cached_spec_path" => env("SWAGGER_CACHED_SPEC_PATH", "doc.json"),
    // "storage" keeps the cache in storage/app/swagger (served only through the protected
    // JSON endpoint). "public" restores the legacy public/{cached_spec_path} file, which
    // anyone can download directly.
    "cached_spec_location" => env("SWAGGER_CACHED_SPEC_LOCATION", "storage"),
    "use_cached_spec_when_present" => env("SWAGGER_USE_CACHED_SPEC", true),

    /*
    |--------------------------------------------------------------------------
    | Mock server (OpenAPI upload)
    |--------------------------------------------------------------------------
    |
    | G4T-hosted mock API (default https://mock.g4t.io). Used by
    | `php artisan swagger:mock-server` to POST generated mock.json.
    | Set MOCK_SERVER_APP_ID in .env to always update that app without prompts.
    | Override MOCK_SERVER_URL only for staging or a private endpoint.
    |
    */
    "mock_server_url" => env("MOCK_SERVER_URL", "https://mock.g4t.io"),
    "mock_server_app_id" => env("MOCK_SERVER_APP_ID"),

    /*
    |--------------------------------------------------------------------------
    | Authentication Middlewares
    |--------------------------------------------------------------------------
    |
    | List of middleware names used for authentication.
    |
    */
    "auth_middlewares" => [
        "auth",
        "auth:api",
        "auth:sanctum",
    ],

    /*
    |--------------------------------------------------------------------------
    | API URL
    |--------------------------------------------------------------------------
    |
    | The URL path for accessing your API documentation.
    |
    */
    "url" => env("SWAGGER_URL", "swagger/documentation"),

    /*
    |--------------------------------------------------------------------------
    | Issues URL
    |--------------------------------------------------------------------------
    |
    | The URL path for accessing issues related to your API documentation.
    |
    */
    "issues_url" => env("SWAGGER_ISSUE_URL", "swagger/issues"),

    /*
    |--------------------------------------------------------------------------
    | Enable Swagger
    |--------------------------------------------------------------------------
    |
    | Whether Swagger is enabled or not. Disabled in production unless
    | SWAGGER_ENABLED=true is set explicitly.
    |
    */
    "enable" => env('SWAGGER_ENABLED', env('APP_ENV', 'production') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Show Prefix
    |--------------------------------------------------------------------------
    |
    | List of prefixes to show in Swagger.
    |
    */
    "show_prefix" => [],

    /*
    |--------------------------------------------------------------------------
    | Include Web Routes
    |--------------------------------------------------------------------------
    |
    | If you want to includes web.php routes, then enable this
    |
    */
    "include_web_routes" => env('SWAGGER_INCLUDE_WEB_ROUTES', false),


    /*
    |--------------------------------------------------------------------------
    | API Versions
    |--------------------------------------------------------------------------
    |
    | List of versions to show in Swagger.
    |
    */
    "versions" => [
        "all",
        // "v1"
    ],

    "default" => "all",


    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | List of servers associated with your API.
    |
    */
    "servers" => [
        [
            "url" => env("APP_URL"),
            "description" => "localhost"
        ]
    ],


    /*
    |--------------------------------------------------------------------------
    | Settings dashboard
    |--------------------------------------------------------------------------
    |
    | A page to edit these settings and reorder / hide endpoints without
    | touching this file. Changes are saved to storage/app/swagger/settings.json
    | and override this file; keys never changed there keep following it.
    |
    | The dashboard only exists when both username and password are set. The
    | password may be plain text or a bcrypt hash (php artisan tinker:
    | Hash::make('secret')).
    |
    */
    "dashboard" => [
        "enabled" => env("SWAGGER_DASHBOARD_ENABLED", true),
        "url" => env("SWAGGER_DASHBOARD_URL", "swagger/dashboard"),
        "username" => env("SWAGGER_DASHBOARD_USERNAME"),
        "password" => env("SWAGGER_DASHBOARD_PASSWORD"),
        // Where saved settings go, relative to the project root (or an absolute path). Outside public/,
        // so it is never served; commit it to ship the same settings to every server.
        "settings_path" => env("SWAGGER_DASHBOARD_SETTINGS_PATH", "swagger-settings.json"),
        // The dashboard needs sessions and CSRF protection: keep "web".
        "middleware" => ["web"],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation UI
    |--------------------------------------------------------------------------
    |
    | "g4t" (the package's own reference UI, bundled, no CDN), "swagger"
    | (Swagger UI, bundled) or "scalar" (Scalar API Reference, loaded from
    | jsDelivr at the pinned "scalar.version"). Append ?ui=g4t, ?ui=swagger or
    | ?ui=scalar to the documentation URL to switch for a single visit.
    |
    | "theme" is the default Swagger UI theme (a file name from
    | public/g4t/swagger/themes without .css). A theme picked in the browser
    | still wins for that browser.
    |
    */
    "ui" => env("SWAGGER_UI", "g4t"),

    // Defaults of the "g4t" UI; readers can still change them from the Appearance menu.
    "g4t" => [
        // light | dark | system
        "mode" => env("SWAGGER_G4T_MODE", "light"),
        // comfortable | compact
        "density" => env("SWAGGER_G4T_DENSITY", "comfortable"),
    ],

    "theme" => env("SWAGGER_THEME", "default"),

    "scalar" => [
        "version" => env("SWAGGER_SCALAR_VERSION", "1.73.0"),
        // default, alternate, moon, purple, solarized, bluePlanet, deepSpace,
        // saturn, kepler, elysiajs, fastify, mars, laserwave, none
        "theme" => env("SWAGGER_SCALAR_THEME", "purple"),
        // modern | classic
        "layout" => env("SWAGGER_SCALAR_LAYOUT", "modern"),
        // null lets the reader toggle, true/false forces dark/light mode.
        "dark_mode" => null,
        // UI language, e.g. "ar" (direction "rtl") or "en".
        "locale" => env("SWAGGER_SCALAR_LOCALE"),
        // auto | ltr | rtl
        "direction" => env("SWAGGER_SCALAR_DIRECTION", "auto"),
        "hide_models" => false,
        "default_open_all_tags" => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Login ("Try it out")
    |--------------------------------------------------------------------------
    |
    | Adds a Login button to the documentation. It sends the credentials to
    | your login endpoint, reads the token from the JSON response and fills
    | the security scheme, so protected endpoints work in "Try it out".
    | The token is kept in the browser (localStorage) until Logout.
    |
    | "url": path or absolute URL of the login endpoint. null disables it.
    | "fields": request field => input label.
    | "token_path": dot path of the token in the response; when it is missing
    |   the common paths (token, access_token, data.token, ...) are tried.
    | "security_scheme": key in "security_schemes" that receives the token.
    | "prefill": values to pre-fill the form, only used in the local env.
    |
    */
    "auto_login" => [
        "url" => env("SWAGGER_LOGIN_URL"),
        "method" => "POST",
        "fields" => [
            "email" => "Email",
            "password" => "Password",
        ],
        "token_path" => env("SWAGGER_LOGIN_TOKEN_PATH"),
        "security_scheme" => "bearerAuth",
        "prefill" => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Security schemes used in your API.
    |
    */
    "security_schemes" => [
        "bearerAuth" => [
            "type" => "http",
            "scheme" => "bearer",
        ],
        // A key sent in a header (use "in" => "query" for ?api_key=...):
        // "apiKey" => [
        //     "type" => "apiKey",
        //     "name" => "X-API-Key",
        //     "in" => "header",
        // ],
        // HTTP Basic (username + password):
        // "basicAuth" => [
        //     "type" => "http",
        //     "scheme" => "basic",
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global headers
    |--------------------------------------------------------------------------
    |
    | Header parameters added to every documented operation, so every UI shows
    | an input for them in "Try it out". Each entry: name, description,
    | required (bool), example, default, enum. A plain string is just a name.
    |
    */
    "global_headers" => [
        // ["name" => "Accept-Language", "description" => "Response language", "enum" => ["en", "ar"], "default" => "en"],
        // ["name" => "X-Tenant", "required" => true, "example" => "acme"],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared environments (g4t UI)
    |--------------------------------------------------------------------------
    |
    | Environments every reader can switch between in the g4t UI: a base URL,
    | {{variables}} usable in any input, and headers sent with every request.
    | Readers can still create their own. Everything here is visible in the
    | page, so never put secrets in it.
    |
    */
    "environments" => [
        // [
        //     "name" => "Staging",
        //     "base_url" => "https://staging.example.com",
        //     "variables" => ["tenant" => "acme", "user_id" => "1"],
        //     "headers" => ["X-Tenant" => "{{tenant}}"],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spatie Query Builder
    |--------------------------------------------------------------------------
    |
    | Enable it if you using Spatie query builder package to add spatie filters in all GET routes.
    |
    */
    "spatie_query_builder" => false,


    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    |
    | Optional per-method response map. Empty by default. Add codes here or use inferred
    | examples and storage/swagger/.../*.json.
    |
    */
    "status" => [
        "GET" => [],
        "POST" => [],
        "PUT" => [],
        "PATCH" => [],
        "DELETE" => [],
    ],

];
