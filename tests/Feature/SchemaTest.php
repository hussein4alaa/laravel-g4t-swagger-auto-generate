<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_get_form_request_rules_become_query_parameters(): void
    {
        $operation = $this->operation('/api/v1/posts', 'get');
        $this->assertArrayNotHasKey('requestBody', $operation);

        $params = collect($operation['parameters'])->where('in', 'query')->keyBy('name');
        $this->assertEqualsCanonicalizing(['search', 'page', 'status'], $params->keys()->all());

        $this->assertSame('integer', $params['page']['schema']['type']);
        $this->assertTrue($params['page']['required']);
        $this->assertFalse($params['search']['required']);
        $this->assertSame(['draft', 'published'], $params['status']['schema']['enum']);
    }

    public function test_post_body_schema_has_types_enums_and_required_fields(): void
    {
        $schema = $this->requestSchema('/api/v1/posts', 'post');
        $props = $schema['properties'];

        $this->assertSame('object', $schema['type']);
        $this->assertSame('string', $props['title']['type']);
        $this->assertSame('string', $props['body']['type']);
        $this->assertSame(['draft', 'published'], $props['status']['enum']);
        $this->assertSame(['low', 'medium', 'high'], $props['priority']['enum']);
        $this->assertSame('integer', $props['category_id']['type']);
        $this->assertSame('uuid', $props['reference']['format'] ?? null);
        $this->assertSame('string', $props['published_at']['type']);
        $this->assertContains($props['published_at']['format'] ?? null, ['date', 'date-time']);
        $this->assertSame('boolean', $props['is_featured']['type']);
        $this->assertSame('number', $props['price']['type']);

        $required = $schema['required'] ?? [];
        foreach (['title', 'status', 'priority', 'category_id', 'password', 'author', 'items'] as $field) {
            $this->assertContains($field, $required, "{$field} should be required");
        }
        foreach (['body', 'reference', 'published_at', 'is_featured', 'price', 'reason', 'tags'] as $field) {
            $this->assertNotContains($field, $required, "{$field} should not be required");
        }
    }

    public function test_post_body_is_offered_as_json_and_form_data(): void
    {
        $content = $this->operation('/api/v1/posts', 'post')['requestBody']['content'];

        $this->assertArrayHasKey('application/json', $content);
        $this->assertArrayHasKey('multipart/form-data', $content);
    }

    public function test_confirmed_rule_adds_confirmation_field(): void
    {
        $props = $this->requestSchema('/api/v1/posts', 'post')['properties'];

        $this->assertArrayHasKey('password_confirmation', $props);
        $this->assertSame('string', $props['password_confirmation']['type']);
    }

    public function test_nested_keys_become_an_object_schema(): void
    {
        $author = $this->requestSchema('/api/v1/posts', 'post')['properties']['author'];

        $this->assertSame('object', $author['type']);
        $this->assertSame('string', $author['properties']['name']['type']);
        $this->assertSame('email', $author['properties']['email']['format'] ?? null);
        $this->assertEqualsCanonicalizing(['name', 'email'], $author['required']);
    }

    public function test_wildcard_keys_become_array_schemas(): void
    {
        $props = $this->requestSchema('/api/v1/posts', 'post')['properties'];

        $this->assertSame('array', $props['tags']['type']);
        $this->assertSame('string', $props['tags']['items']['type']);

        $this->assertSame('array', $props['items']['type']);
        $this->assertSame('object', $props['items']['items']['type']);
        $this->assertSame('string', $props['items']['items']['properties']['sku']['type']);
        $this->assertSame('integer', $props['items']['items']['properties']['qty']['type']);
        $this->assertEqualsCanonicalizing(['sku', 'qty'], $props['items']['items']['required']);
    }

    public function test_form_data_schema_uses_bracket_notation(): void
    {
        $props = $this->requestSchema('/api/v1/posts', 'post', 'multipart/form-data')['properties'];

        $this->assertArrayHasKey('author[name]', $props);
        $this->assertArrayHasKey('tags[]', $props);
        $this->assertArrayHasKey('items[0][sku]', $props);
    }

    public function test_api_resource_update_has_a_request_body(): void
    {
        $spec = $this->spec();
        $operations = $spec['paths']['/api/v1/posts/{post}'] ?? [];
        $this->assertTrue(isset($operations['put']) || isset($operations['patch']), 'update (PUT|PATCH) is not documented');

        foreach (array_intersect_key($operations, ['put' => 1, 'patch' => 1]) as $method => $operation) {
            $this->assertArrayHasKey('requestBody', $operation, "{$method} update has no requestBody");
            $schema = $this->resolveSchema($operation['requestBody']['content']['application/json']['schema'], $spec);
            $this->assertArrayHasKey('title', $schema['properties']);
            $this->assertSame(['draft', 'published'], $schema['properties']['status']['enum']);
        }
    }

    public function test_explicit_patch_route_has_a_request_body(): void
    {
        $schema = $this->requestSchema('/api/v1/settings', 'patch');

        $this->assertSame(['en', 'ar'], $schema['properties']['locale']['enum']);
        $this->assertSame('boolean', $schema['properties']['notifications']['type']);
        $this->assertContains('locale', $schema['required']);
    }

    public function test_file_fields_are_binary_multipart_fields(): void
    {
        $operation = $this->operation('/api/v1/media', 'post');
        $this->assertArrayHasKey('multipart/form-data', $operation['requestBody']['content']);
        $this->assertArrayNotHasKey('application/json', $operation['requestBody']['content'], 'File uploads cannot be sent as JSON');

        $props = $this->requestSchema('/api/v1/media', 'post', 'multipart/form-data')['properties'];
        $this->assertSame(['type' => 'string', 'format' => 'binary'], array_intersect_key($props['cover'], ['type' => 1, 'format' => 1]));
        $this->assertSame('binary', $props['document']['format'] ?? null);

        $gallery = $props['gallery[]'] ?? $props['gallery'] ?? null;
        $this->assertNotNull($gallery, 'gallery array of files is missing');
        $this->assertSame('array', $gallery['type']);
        $this->assertSame('binary', $gallery['items']['format'] ?? null);

        $this->assertSame('string', $props['caption']['type']);
        $this->assertArrayNotHasKey('format', $props['caption']);
    }

    public function test_put_with_file_is_documented_as_multipart_with_method_spoofing(): void
    {
        $operations = $this->spec()['paths']['/api/v1/media/{id}'] ?? [];
        $this->assertNotEmpty($operations, 'PUT media/{id} is not documented');

        $method = isset($operations['post']) ? 'post' : 'put';
        $props = $this->requestSchema('/api/v1/media/{id}', $method, 'multipart/form-data')['properties'];

        $this->assertSame('binary', $props['file']['format'] ?? null);
        if ($method === 'post') {
            $this->assertSame(['PUT'], $props['_method']['enum']);
        }

        $id = collect($operations[$method]['parameters'])->firstWhere('name', 'id');
        $this->assertSame('path', $id['in']);
        $this->assertSame('integer', $id['schema']['type']);
    }

    public function test_optional_path_parameter_is_expanded_into_variants(): void
    {
        $paths = $this->spec()['paths'];

        $this->assertArrayHasKey('/api/v1/records/{id}', $paths);
        $this->assertArrayHasKey('/api/v1/records', $paths);
        $this->assertArrayNotHasKey('/api/v1/records/{id?}', $paths);

        $id = collect($paths['/api/v1/records/{id}']['get']['parameters'])->firstWhere('name', 'id');
        $this->assertSame('path', $id['in']);
        $this->assertTrue($id['required']);

        $this->assertNull(collect($paths['/api/v1/records']['get']['parameters'])->firstWhere('name', 'id'));
    }

    public function test_route_model_binding_parameter_is_an_integer(): void
    {
        $post = collect($this->operation('/api/v1/posts/{post}', 'get')['parameters'])->firstWhere('name', 'post');

        $this->assertSame('path', $post['in']);
        $this->assertTrue($post['required']);
        $this->assertSame('integer', $post['schema']['type']);
    }

    public function test_get_and_delete_routes_have_no_request_body(): void
    {
        $this->assertArrayNotHasKey('requestBody', $this->operation('/api/v1/ping', 'get'));
        $this->assertArrayNotHasKey('requestBody', $this->operation('/api/v1/posts/{post}', 'delete'));
    }
}
