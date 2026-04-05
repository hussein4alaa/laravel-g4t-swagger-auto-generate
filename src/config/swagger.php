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
    "enable_auth" => false,
    "username" => "admin",
    "password" => "pass",
    "sesson_ttl" => 100000,

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
    | endpoint serves public/{cached_spec_path} if that file exists (e.g. after
    | `php artisan swagger:cache` or `php artisan make:swagger`). Otherwise it builds
    | the spec from routes on each request.
    |
    | When load_from_json is true, only the file is used (same as before).
    |
    */
    "cached_spec_path" => env("SWAGGER_CACHED_SPEC_PATH", "doc.json"),
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
    | Whether Swagger is enabled or not.
    |
    */
    "enable" => env('SWAGGER_ENABLED', true),

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
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Security schemes used in your API.
    |
    */
    "security_schemes" => [
        "authorization" => [
            "type" => "apiKey",
            "name" => "authorization",
            "in" => "header"
        ],
        "apiKey1" => [
            "type" => "apiKey",
            "name" => "key1",
            "in" => "query"
        ]
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
