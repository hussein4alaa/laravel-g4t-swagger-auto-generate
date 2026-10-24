<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class ResponseSchemaTest extends TestCase
{
    public function test_single_resource_response_references_a_component(): void
    {
        $operation = $this->operation('/api/v1/posts/{post}', 'get');

        $this->assertSame(
            ['$ref' => '#/components/schemas/Post'],
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    public function test_resource_component_is_typed_from_casts_and_enums(): void
    {
        $post = $this->spec()['components']['schemas']['Post'];

        $this->assertSame('object', $post['type']);
        $this->assertSame('integer', $post['properties']['id']['type']);
        $this->assertSame('boolean', $post['properties']['is_featured']['type']);
        $this->assertSame(['draft', 'published'], $post['properties']['status']['enum']);
        $this->assertSame('date-time', $post['properties']['created_at']['format']);
    }

    public function test_nested_resource_is_referenced_and_optional(): void
    {
        $post = $this->spec()['components']['schemas']['Post'];

        $this->assertSame(['$ref' => '#/components/schemas/Category'], $post['properties']['category']);
        $this->assertNotContains('category', $post['required']);
        $this->assertContains('id', $post['required']);
        $this->assertArrayHasKey('Category', $this->spec()['components']['schemas']);
    }

    public function test_paginated_collection_wraps_items_in_data(): void
    {
        $schema = $this->operation('/api/v1/posts', 'get')['responses']['200']['content']['application/json']['schema'];

        $this->assertSame('object', $schema['type']);
        $this->assertSame('array', $schema['properties']['data']['type']);
        $this->assertSame(['$ref' => '#/components/schemas/Post'], $schema['properties']['data']['items']);
        $this->assertSame('object', $schema['properties']['meta']['type']);
    }

    public function test_plain_collection_is_an_array_of_references(): void
    {
        $schema = $this->operation('/api/v1/categories', 'get')['responses']['200']['content']['application/json']['schema'];

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['$ref' => '#/components/schemas/Category'], $schema['items']);
    }

    public function test_returned_model_references_a_model_component(): void
    {
        $schema = $this->operation('/api/v1/posts-latest', 'get')['responses']['200']['content']['application/json']['schema'];

        $this->assertArrayHasKey('$ref', $schema);
        $name = substr($schema['$ref'], strlen('#/components/schemas/'));
        $this->assertContains($name, ['Post', 'PostModel']);
        $this->assertArrayHasKey($name, $this->spec()['components']['schemas']);
    }

    public function test_literal_json_payload_gets_an_inline_typed_schema(): void
    {
        $schema = $this->operation('/api/v1/ping', 'get')['responses']['200']['content']['application/json']['schema'];

        $this->assertSame('object', $schema['type']);
        $this->assertSame('string', $schema['properties']['status']['type']);
        $this->assertSame('integer', $schema['properties']['uptime']['type']);
    }

    public function test_error_examples_get_a_schema(): void
    {
        $json = $this->operation('/api/v1/posts', 'post')['responses']['409']['content']['application/json'];

        $this->assertSame('string', $json['schema']['properties']['message']['type']);
    }

    public function test_every_reference_resolves_and_no_marker_leaks(): void
    {
        $raw = json_encode($this->spec());
        $this->assertStringNotContainsString('\u0000', $raw);
        $this->assertStringNotContainsString('g4t:class', $raw);

        $spec = $this->spec();
        preg_match_all('~"\$ref":"#\\\\/components\\\\/schemas\\\\/([^"]+)"~', $raw, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $name) {
            $this->assertArrayHasKey($name, $spec['components']['schemas'], "Dangling \$ref to {$name}");
        }
    }

    public function test_response_schemas_can_be_turned_off(): void
    {
        config(['swagger.response_schemas' => false]);

        $json = $this->operation('/api/v1/posts/{post}', 'get')['responses']['200']['content']['application/json'];

        $this->assertArrayNotHasKey('schema', $json);
        $this->assertArrayHasKey('example', $json);
        $this->assertArrayNotHasKey('Post', $this->spec()['components']['schemas']);
    }
}
