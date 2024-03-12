<?php

return [

    "title" => env("SWAGGER_TITLE","Laravel G4T Documentation"),
    
    "description" => env("SWAGGER_DESCRIPTION", "Laravel autogenerate swagger"),

    "email" => env("SWAGGER_EMAIL","hussein4alaa@gmail.com"),

    "version" => env("SWAGGER_VERSION", "1.0.0"),

    "enable_response_schema" => true,

    "stop_saving_response" => true,

    "auth_middlewares" => [
        "auth",
        "auth:api"
    ],

    "url" => env("SWAGGER_URL", "swagger/documentation"),
    
    "issues_url" => env("SWAGGER_ISSUE_URL", "swagger/issues"),


    "enable" => env('SWAGGER_ENABLED', true),
    
    "show_prefix" => [],

    "servers" => [
        [
            "url" => env("APP_URL"),
            "description" => "localhost"    
        ],
        [
            "url" => "http://localhost",
            "description" => "production"    
        ],
    ],

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
        ],
    ],

];
