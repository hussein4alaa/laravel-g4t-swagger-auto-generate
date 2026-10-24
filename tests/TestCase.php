<?php

namespace G4T\Swagger\Tests;

use G4T\Swagger\Swagger;
use G4T\Swagger\SwaggerServiceProvider;
use G4T\Swagger\Tests\Fixtures\Controllers\V1\MediaController;
use G4T\Swagger\Tests\Fixtures\Controllers\V1\MiscController;
use G4T\Swagger\Tests\Fixtures\Controllers\V1\PostController;
use G4T\Swagger\Tests\Fixtures\Controllers\V1\ProductController as V1ProductController;
use G4T\Swagger\Tests\Fixtures\Controllers\V2\ProductController as V2ProductController;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SwaggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');

        // The package does not merge its own config, so load the shipped defaults explicitly.
        $app['config']->set('swagger', array_merge(require __DIR__.'/../src/config/swagger.php', [
            'use_cached_spec_when_present' => false,
            'cached_spec_path' => 'g4t-swagger-test-doc.json',
            'versions' => ['all', 'v1', 'v2'],
            'servers' => [['url' => 'http://localhost', 'description' => 'testing']],
        ]));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api')->group(function (Router $router) {
            $router->prefix('v1')->group(function (Router $router) {
                $router->apiResource('posts', PostController::class);

                $router->post('media', [MediaController::class, 'upload']);
                $router->put('media/{id}', [MediaController::class, 'replace']);

                $router->get('ping', [MiscController::class, 'ping']);
                $router->get('stats', [MiscController::class, 'stats']);
                $router->get('categories', [MiscController::class, 'categories']);
                $router->get('posts-latest', [MiscController::class, 'latestPost']);
                $router->get('records/{id?}', [MiscController::class, 'find']);
                $router->post('conflict', [MiscController::class, 'conflict']);
                $router->patch('settings', [MiscController::class, 'settings']);
                $router->get('profile', [MiscController::class, 'profile'])->middleware('auth');
                $router->get('documented', [MiscController::class, 'documented'])
                    ->summary('Documented endpoint')
                    ->description('A route documented through route macros.');
                $router->get('secret', [MiscController::class, 'secret'])->hiddenDoc();
                $router->get('closure', fn () => ['closure' => true]);

                $router->get('products', [V1ProductController::class, 'index']);
            });

            $router->prefix('v2')->group(function (Router $router) {
                $router->get('products', [V2ProductController::class, 'index']);
            });
        });

        // Not an API route: must never be documented.
        $router->get('web-only', [MiscController::class, 'ping']);
    }

    /**
     * The generated document, round-tripped through JSON so objects/lists look like a client would see them.
     *
     * @return array<string, mixed>
     */
    protected function spec(): array
    {
        return json_decode(json_encode((new Swagger)->swagger()), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function operation(string $path, string $method): array
    {
        $spec = $this->spec();
        $this->assertArrayHasKey($path, $spec['paths'], "Path {$path} is not documented. Got: ".implode(', ', array_keys($spec['paths'])));
        $this->assertArrayHasKey($method, $spec['paths'][$path], "{$method} {$path} is not documented.");

        return $spec['paths'][$path][$method];
    }

    /**
     * Resolve a `#/components/schemas/X` reference (or return an inline schema as-is).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function resolveSchema(array $schema, ?array $spec = null): array
    {
        if (! isset($schema['$ref'])) {
            return $schema;
        }

        $spec ??= $this->spec();
        $name = substr($schema['$ref'], strlen('#/components/schemas/'));
        $this->assertArrayHasKey($name, $spec['components']['schemas'], "Unresolvable schema reference {$schema['$ref']}");

        return $spec['components']['schemas'][$name];
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestSchema(string $path, string $method, string $contentType = 'application/json'): array
    {
        $operation = $this->operation($path, $method);
        $this->assertArrayHasKey('requestBody', $operation, "{$method} {$path} has no requestBody.");
        $this->assertArrayHasKey($contentType, $operation['requestBody']['content'], "{$method} {$path} has no {$contentType} body.");

        return $this->resolveSchema($operation['requestBody']['content'][$contentType]['schema']);
    }

    /**
     * Example payload of a response, whether given as `example` or as the first of `examples`.
     */
    protected function responseExample(array $operation, string|int $status): mixed
    {
        $status = (string) $status;
        $this->assertArrayHasKey($status, $operation['responses'], "No {$status} response documented.");
        $json = $operation['responses'][$status]['content']['application/json'] ?? [];

        if (array_key_exists('example', $json)) {
            return $json['example'];
        }
        if (! empty($json['examples'])) {
            return array_values($json['examples'])[0]['value'];
        }

        $this->fail("The {$status} response has no example.");
    }
}
