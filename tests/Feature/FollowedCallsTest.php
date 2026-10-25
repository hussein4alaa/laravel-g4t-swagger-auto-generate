<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\Fixtures\Controllers\V1\TicketController;
use G4T\Swagger\Tests\Fixtures\Repositories\TicketRepository;
use G4T\Swagger\Tests\Fixtures\Repositories\TicketRepositoryInterface;
use G4T\Swagger\Tests\TestCase;
use Illuminate\Routing\Router;

/**
 * Controller → repository (interface bound in the container) → static response helper.
 */
class FollowedCallsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->bind(TicketRepositoryInterface::class, TicketRepository::class);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        /** @var Router $router */
        $router->middleware('api')->prefix('api/v1')->group(function (Router $router) {
            $router->post('tickets', [TicketController::class, 'store']);
            $router->get('tickets/{id}', [TicketController::class, 'show']);
            $router->get('legacy-tickets/{id}', [TicketController::class, 'legacy']);
            $router->post('tickets/close', [TicketController::class, 'close']);
        });
    }

    public function test_the_helper_response_of_a_bound_repository_is_the_success_example(): void
    {
        $operation = $this->operation('/api/v1/tickets', 'post');

        $this->assertSame(['status' => true, 'message' => 'Ticket created', 'data' => null], $this->responseExample($operation, 200));
    }

    public function test_error_helpers_become_error_examples_with_their_status(): void
    {
        $operation = $this->operation('/api/v1/tickets', 'post');

        $this->assertSame(['status' => false, 'message' => 'Post not found', 'errors' => null], $this->responseExample($operation, 404));
        $this->assertSame(['status' => false, 'message' => 'This post has no category yet.', 'errors' => null], $this->responseExample($operation, 403));
    }

    public function test_arguments_that_are_not_literals_become_typed_placeholders(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/tickets/{id}', 'get'), 200);

        $this->assertSame(['status' => true, 'message' => 'Ticket', 'data' => ['id' => 1, 'title' => 'Broken screen']], $example);
    }

    public function test_a_property_assigned_in_the_constructor_is_followed(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/legacy-tickets/{id}', 'get'), 200);

        $this->assertSame('Ticket', $example['message']);
    }

    public function test_the_status_of_a_followed_method_is_used(): void
    {
        $operation = $this->operation('/api/v1/tickets/close', 'post');

        $this->assertArrayNotHasKey('200', $operation['responses']);
        $this->assertSame(['queued' => true], $this->responseExample($operation, 202));
    }

    public function test_an_unbound_interface_is_not_followed(): void
    {
        unset($this->app[TicketRepositoryInterface::class]);

        $operation = $this->operation('/api/v1/tickets', 'post');

        $this->assertArrayNotHasKey('404', $operation['responses']);
        $this->assertArrayNotHasKey('content', $operation['responses']['200'] ?? []);
    }

    public function test_the_response_schema_follows_the_example(): void
    {
        $schema = $this->resolveSchema($this->operation('/api/v1/tickets', 'post')['responses']['200']['content']['application/json']['schema']);

        $this->assertEqualsCanonicalizing(['status', 'message', 'data'], array_keys($schema['properties']));
        $this->assertSame('boolean', $schema['properties']['status']['type']);
    }
}
