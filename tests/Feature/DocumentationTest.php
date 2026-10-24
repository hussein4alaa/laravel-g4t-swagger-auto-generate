<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_documentation_page_is_rendered(): void
    {
        $this->get('/swagger/documentation')->assertOk();
    }

    public function test_json_endpoint_returns_an_openapi_document(): void
    {
        $response = $this->getJson('/swagger/documentation/json?version=all')->assertOk();

        $spec = $response->json();
        foreach (['openapi', 'info', 'servers', 'paths', 'tags', 'components'] as $key) {
            $this->assertArrayHasKey($key, $spec, "Missing root key {$key}");
        }
        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertNotEmpty($spec['paths']);
    }

    public function test_every_operation_has_a_responses_object_and_unique_operation_id(): void
    {
        // Decode to stdClass so a JSON list `[]` (invalid for `responses`) can be told apart from an object `{}`.
        $spec = json_decode($this->getJson('/swagger/documentation/json?version=all')->getContent());

        $operationIds = [];
        foreach ((array) $spec->paths as $path => $operations) {
            foreach ((array) $operations as $method => $operation) {
                $this->assertContains($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], "{$path}: unexpected key {$method}");
                $this->assertObjectHasProperty('responses', $operation, "{$method} {$path} has no responses");
                $this->assertIsObject($operation->responses, "{$method} {$path}: responses must be a JSON object");
                $this->assertNotEmpty((array) $operation->responses, "{$method} {$path}: responses must not be empty");
                $this->assertObjectHasProperty('operationId', $operation);
                $operationIds[] = $operation->operationId;
            }
        }

        $this->assertSame(array_unique($operationIds), $operationIds, 'operationIds must be unique');
    }

    public function test_every_schema_reference_resolves(): void
    {
        $spec = $this->getJson('/swagger/documentation/json?version=all')->json();

        $refs = [];
        array_walk_recursive($spec, function ($value, $key) use (&$refs) {
            if ($key === '$ref') {
                $refs[] = $value;
            }
        });

        $this->assertNotEmpty($refs);
        foreach (array_unique($refs) as $ref) {
            $this->assertStringStartsWith('#/components/schemas/', $ref);
            $name = substr($ref, strlen('#/components/schemas/'));
            $this->assertArrayHasKey($name, $spec['components']['schemas'], "Dangling \$ref {$ref}");
        }
    }

    public function test_hidden_closure_and_non_api_routes_are_not_documented(): void
    {
        $paths = array_keys($this->spec()['paths']);

        $this->assertNotContains('/api/v1/secret', $paths);
        $this->assertNotContains('/api/v1/closure', $paths);
        $this->assertNotContains('/web-only', $paths);
        $this->assertContains('/api/v1/ping', $paths);
    }

    public function test_summary_and_description_macros_are_applied(): void
    {
        $operation = $this->operation('/api/v1/documented', 'get');

        $this->assertSame('Documented endpoint', $operation['summary']);
        $this->assertSame('A route documented through route macros.', $operation['description']);
    }

    public function test_swagger_section_attribute_sets_tag_description(): void
    {
        $spec = $this->spec();
        $tags = collect($spec['tags'])->keyBy('name');

        $postTag = $this->operation('/api/v1/posts', 'get')['tags'][0];
        $this->assertTrue($tags->has($postTag), "Tag {$postTag} is not declared at the root");
        $this->assertSame('Blog posts management', $tags[$postTag]['description'] ?? null);
    }

    public function test_every_operation_tag_is_declared(): void
    {
        $spec = $this->spec();
        $declared = array_column($spec['tags'], 'name');

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach ($operation['tags'] as $tag) {
                    $this->assertContains($tag, $declared, "{$method} {$path} uses undeclared tag {$tag}");
                }
            }
        }
    }

    public function test_controllers_sharing_a_short_name_get_distinct_tags(): void
    {
        $v1 = $this->operation('/api/v1/products', 'get')['tags'][0];
        $v2 = $this->operation('/api/v2/products', 'get')['tags'][0];

        $this->assertNotSame($v1, $v2);
    }

    public function test_version_filter_only_returns_that_version(): void
    {
        $v1 = array_keys($this->getJson('/swagger/documentation/json?version=v1')->assertOk()->json('paths'));
        $this->assertNotEmpty($v1);
        foreach ($v1 as $path) {
            $this->assertStringStartsWith('/api/v1/', $path);
        }

        $v2 = array_keys($this->getJson('/swagger/documentation/json?version=v2')->assertOk()->json('paths'));
        $this->assertSame(['/api/v2/products'], $v2);

        $all = array_keys($this->getJson('/swagger/documentation/json?version=all')->json('paths'));
        $this->assertContains('/api/v1/products', $all);
        $this->assertContains('/api/v2/products', $all);
    }

    public function test_documentation_is_forbidden_when_disabled(): void
    {
        config(['swagger.enable' => false]);

        $this->getJson('/swagger/documentation/json')->assertForbidden();
    }
}
