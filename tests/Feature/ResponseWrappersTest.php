<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Inference\ModelInspector;
use G4T\Swagger\Inference\ResponseSchema;
use G4T\Swagger\Tests\Fixtures\Controllers\V1\WrappedController;
use G4T\Swagger\Tests\Fixtures\Models\Post;
use G4T\Swagger\Tests\TestCase;
use Illuminate\Routing\Router;

require_once __DIR__.'/../Fixtures/Support/helpers.php';

/**
 * Responses returned through a project's own wrapper: Responder::success($data), a trait method or a helper function.
 */
class ResponseWrappersTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        /** @var Router $router */
        $router->middleware('api')->prefix('api/v1')->group(function (Router $router) {
            $router->get('wrapped', [WrappedController::class, 'index']);
            $router->post('wrapped', [WrappedController::class, 'store']);
            $router->get('wrapped/{id}', [WrappedController::class, 'show']);
            $router->get('wrapped-model/{id}', [WrappedController::class, 'model']);
            $router->get('wrapped-assigned/{id}', [WrappedController::class, 'assigned']);
            $router->get('wrapped-helper/{id}', [WrappedController::class, 'helper']);
            $router->post('wrapped-token', [WrappedController::class, 'token']);
            $router->get('wrapped-trait/{id}', [WrappedController::class, 'trait']);
            $router->get('wrapped-missing', [WrappedController::class, 'missing']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function documented(string $path, string $method, int $status): array
    {
        return $this->operation($path, $method)['responses'][(string) $status]['content']['application/json'];
    }

    public function test_a_resource_inside_the_wrapper_keeps_its_schema(): void
    {
        $json = $this->documented('/api/v1/wrapped/{id}', 'get', 200);

        $this->assertSame(['success', 'message', 'data'], array_keys($json['example']));
        $this->assertSame('OK', $json['example']['message'], 'Default arguments of the wrapper are used');
        $this->assertSame('#/components/schemas/Post', $json['schema']['properties']['data']['$ref']);
    }

    public function test_the_status_passed_to_the_wrapper_is_the_documented_status(): void
    {
        $operation = $this->operation('/api/v1/wrapped', 'post');

        $this->assertArrayHasKey('201', $operation['responses']);
        $this->assertArrayNotHasKey('200', $operation['responses']);
        $this->assertSame('Post created', $operation['responses']['201']['content']['application/json']['example']['message']);
    }

    public function test_a_paginated_resource_collection_is_wrapped(): void
    {
        $data = $this->documented('/api/v1/wrapped', 'get', 200)['schema']['properties']['data'];

        $this->assertSame('#/components/schemas/Post', $data['properties']['data']['items']['$ref']);
        $this->assertArrayHasKey('meta', $data['properties']);
    }

    public function test_a_model_handed_to_the_wrapper_is_shown_as_the_model(): void
    {
        $row = (new ModelInspector)->modelExample(Post::class);
        unset($row[ResponseSchema::MARKER]);
        $this->assertArrayHasKey('id', $row);

        foreach (['/api/v1/wrapped-model/{id}', '/api/v1/wrapped-assigned/{id}'] as $path) {
            $this->assertSame($row, $this->documented($path, 'get', 200)['example']['data'], $path);
        }
    }

    public function test_wrappers_that_call_other_wrappers_and_trait_methods_are_followed(): void
    {
        $token = $this->operation('/api/v1/wrapped-token', 'post');
        $this->assertSame(['success' => true, 'message' => 'Created', 'data' => ['token' => 'abc123']], $token['responses']['201']['content']['application/json']['example']);

        $trait = $this->documented('/api/v1/wrapped-trait/{id}', 'get', 200);
        $this->assertSame(['status', 'message', 'result'], array_keys($trait['example']));
        $this->assertSame('#/components/schemas/Post', $trait['schema']['properties']['result']['$ref']);
    }

    public function test_a_global_helper_function_of_the_project_is_followed(): void
    {
        $json = $this->documented('/api/v1/wrapped-helper/{id}', 'get', 200);

        $this->assertSame('From a helper', $json['example']['message']);
        $this->assertSame('#/components/schemas/Post', $json['schema']['properties']['data']['$ref']);
    }

    public function test_an_error_wrapper_is_an_error_example(): void
    {
        $json = $this->documented('/api/v1/wrapped-missing', 'get', 404);

        $this->assertSame(['success' => false, 'message' => 'Post not found', 'data' => null], $json['example']);
    }
}
