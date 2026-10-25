<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Extensions\Extensions;
use G4T\Swagger\Swagger;
use G4T\Swagger\Tests\Fixtures\Extensions\ActionExtension;
use G4T\Swagger\Tests\Fixtures\Extensions\BrokenExtension;
use G4T\Swagger\Tests\Fixtures\Extensions\CreateUserAction;
use G4T\Swagger\Tests\Fixtures\Extensions\DataController;
use G4T\Swagger\Tests\Fixtures\Extensions\DataExtension;
use G4T\Swagger\Tests\Fixtures\Extensions\JsonApiExtension;
use G4T\Swagger\Tests\TestCase;
use Illuminate\Routing\Router;

class ExtensionsTest extends TestCase
{
    protected function setUp(): void
    {
        Extensions::flush();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Extensions::flush();
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('swagger.extensions', [DataExtension::class]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        /** @var Router $router */
        $router->middleware('api')->prefix('api/v1')->group(function (Router $router) {
            $router->post('data/users', [DataController::class, 'store']);
            $router->get('data/users/{id}', [DataController::class, 'show']);
            $router->post('actions/users', CreateUserAction::class);
        });
    }

    public function test_a_request_hook_turns_a_data_parameter_into_the_body(): void
    {
        $body = $this->requestSchema('/api/v1/data/users', 'post');

        $this->assertSame(['id', 'email', 'address', 'roles'], $body['required']);
        $this->assertSame(['type' => 'integer'], $body['properties']['id']);
        $this->assertSame(['type' => 'string', 'format' => 'date-time', 'nullable' => true], $body['properties']['joined_at'], 'Keys go through the key callback');
        $this->assertSame(['city'], $body['properties']['address']['required']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $body['properties']['roles'], 'List items come from @param list<string>');
    }

    public function test_a_response_hook_gives_the_example_and_a_reusable_schema(): void
    {
        $operation = $this->operation('/api/v1/data/users', 'post');
        $json = $operation['responses']['200']['content']['application/json'];

        $this->assertSame(1, $json['example']['id']);
        $this->assertSame(['city', 'zip'], array_keys($json['example']['address']));
        $this->assertSame('#/components/schemas/UserData', $json['schema']['$ref']);

        $components = $this->spec()['components']['schemas'];
        $this->assertSame('#/components/schemas/AddressData', $components['UserData']['properties']['address']['$ref']);
    }

    public function test_values_nested_in_a_json_response_are_asked_too(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/data/users/{id}', 'get'), 200);

        $this->assertSame(['id', 'email', 'address', 'roles', 'joined_at', 'active'], array_keys($example['data']));
    }

    public function test_extensions_registered_from_code_run_after_the_config_ones(): void
    {
        Swagger::extend(ActionExtension::class);
        Swagger::extend(new JsonApiExtension);

        $spec = $this->spec();
        $action = $spec['paths']['/api/v1/actions/users']['post'];
        $schema = $this->resolveSchema($action['requestBody']['content']['application/json']['schema'], $spec);
        $this->assertSame(['name', 'role'], $schema['required']);
        $this->assertSame(['admin', 'editor'], $schema['properties']['role']['enum']);
        $this->assertArrayHasKey('201', $action['responses']);

        $data = $spec['paths']['/api/v1/data/users']['post'];
        $this->assertTrue($data['x-json-api']);
        $this->assertArrayHasKey('application/vnd.api+json', $data['responses']['200']['content']);
        $this->assertArrayNotHasKey('x-json-api', $spec['paths']['/api/v1/ping']['get'], 'Operation hooks see every route and choose');
        $this->assertSame('1.1', $spec['info']['x-json-api-version']);
    }

    public function test_a_broken_extension_never_breaks_the_document(): void
    {
        Swagger::extend(BrokenExtension::class);

        $this->get('/swagger/documentation/json')->assertOk();
        $this->assertSame('#/components/schemas/UserData', $this->operation('/api/v1/data/users', 'post')['responses']['200']['content']['application/json']['schema']['$ref'], 'The other extensions still answer');
    }

    public function test_the_generator_command_writes_an_extension_class(): void
    {
        $path = app_path('Swagger/SpatieDataExtension.php');
        @unlink($path);

        $this->artisan('make:swagger-extension', ['name' => 'SpatieData'])->assertExitCode(0);

        $this->assertFileExists($path);
        $code = file_get_contents($path);
        $this->assertStringContainsString('class SpatieDataExtension extends Extension', $code);
        $this->assertStringContainsString('namespace App\Swagger;', $code);
        @unlink($path);
    }
}
