<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class ResponseExamplesTest extends TestCase
{
    public function test_literal_json_response_becomes_the_success_example(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/ping', 'get'), 200);

        $this->assertSame(['status' => 'ok', 'uptime' => 42], $example);
    }

    public function test_json_response_with_variables_keeps_its_keys(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/stats', 'get'), 200);

        $this->assertIsArray($example);
        $this->assertEqualsCanonicalizing(['total', 'label'], array_keys($example));
    }

    public function test_single_resource_example_uses_resource_fields(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/posts/{post}', 'get'), 200);
        $item = $example['data'] ?? $example;

        $this->assertEqualsCanonicalizing(['id', 'title', 'body', 'is_featured', 'status', 'category', 'created_at'], array_keys($item));
    }

    public function test_resource_collection_example_is_a_list_of_items(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/categories', 'get'), 200);
        $list = $example['data'] ?? $example;

        $this->assertIsArray($list);
        $this->assertTrue(array_is_list($list));
        $this->assertNotEmpty($list);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($list[0]));
    }

    public function test_paginated_resource_collection_example_has_pagination_envelope(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/posts', 'get'), 200);

        $this->assertArrayHasKey('data', $example);
        $this->assertArrayHasKey('links', $example);
        $this->assertArrayHasKey('meta', $example);
        $this->assertArrayHasKey('title', $example['data'][0]);
    }

    public function test_abort_produces_a_not_found_example(): void
    {
        $example = $this->responseExample($this->operation('/api/v1/records/{id}', 'get'), 404);

        $this->assertSame('Record not found.', $example['message'] ?? null);
    }

    public function test_error_json_responses_produce_examples_per_status(): void
    {
        $operation = $this->operation('/api/v1/conflict', 'post');

        $this->assertSame('Resource already locked.', $this->responseExample($operation, 409)['message'] ?? null);

        $unprocessable = $this->responseExample($operation, 422);
        $this->assertSame('The state is invalid.', $unprocessable['message'] ?? null);
        $this->assertArrayHasKey('state', $unprocessable['errors']);
    }

    public function test_form_request_produces_a_validation_example(): void
    {
        $operation = $this->operation('/api/v1/posts', 'post');
        $json = $operation['responses']['422']['content']['application/json'] ?? [];

        $examples = isset($json['example']) ? [$json['example']] : array_column($json['examples'] ?? [], 'value');
        $validation = collect($examples)->first(fn ($example) => isset($example['errors']['title']));

        $this->assertNotNull($validation, 'No Laravel-style validation example for 422');
        $this->assertArrayHasKey('message', $validation);
        $this->assertArrayHasKey('category_id', $validation['errors']);
        $this->assertArrayNotHasKey('body', $validation['errors'], 'Optional fields must not be listed as required');
    }

    public function test_store_documents_both_success_and_conflict(): void
    {
        $operation = $this->operation('/api/v1/posts', 'post');
        $success = collect($operation['responses'])->keys()->first(fn ($code) => $code >= 200 && $code < 300);

        $this->assertNotNull($success, 'store has no 2xx response');
        $this->assertSame('A post with this title already exists.', $this->responseExample($operation, 409)['message'] ?? null);
    }

    public function test_no_content_response_is_documented_as_204(): void
    {
        $responses = $this->operation('/api/v1/posts/{post}', 'delete')['responses'];

        $this->assertArrayHasKey('204', $responses);
        $this->assertArrayNotHasKey('content', $responses['204']);
    }

    public function test_examples_can_be_disabled(): void
    {
        config(['swagger.infer_response_examples' => false, 'swagger.infer_error_response_examples' => false]);

        $responses = $this->operation('/api/v1/ping', 'get')['responses'];

        $this->assertArrayNotHasKey('example', $responses['200']['content']['application/json'] ?? []);
    }
}
