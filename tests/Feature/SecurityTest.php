<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class SecurityTest extends TestCase
{
    public function test_route_with_auth_middleware_requires_security(): void
    {
        $spec = $this->spec();
        $operation = $spec['paths']['/api/v1/profile']['get'];

        $this->assertNotEmpty($operation['security'] ?? []);
        foreach ($operation['security'] as $requirement) {
            foreach (array_keys($requirement) as $scheme) {
                $this->assertArrayHasKey($scheme, $spec['components']['securitySchemes']);
            }
        }
    }

    public function test_public_route_has_no_security_requirement(): void
    {
        $this->assertEmpty($this->operation('/api/v1/ping', 'get')['security'] ?? []);
    }

    public function test_docs_are_public_when_basic_auth_is_disabled(): void
    {
        $this->getJson('/swagger/documentation/json')->assertOk();
    }

    /**
     * Runs in its own process: a middleware that calls exit() must not kill the whole suite.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_basic_auth_rejects_missing_credentials(): void
    {
        config(['swagger.enable_auth' => true]);

        $this->get('/swagger/documentation/json')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_basic_auth_accepts_valid_credentials(): void
    {
        config(['swagger.enable_auth' => true, 'swagger.username' => 'admin', 'swagger.password' => 'secret']);

        $this->get('/swagger/documentation/json', ['Authorization' => 'Basic '.base64_encode('admin:secret')])
            ->assertOk();
    }

    public function test_basic_auth_rejects_wrong_credentials(): void
    {
        config(['swagger.enable_auth' => true, 'swagger.username' => 'admin', 'swagger.password' => 'secret']);

        $this->get('/swagger/documentation/json', ['Authorization' => 'Basic '.base64_encode('admin:wrong')])
            ->assertUnauthorized();
    }

    public function test_basic_auth_rejects_malformed_header_with_401(): void
    {
        config(['swagger.enable_auth' => true]);

        // "Zm9v" is base64 for "foo": no ":" separator.
        $this->get('/swagger/documentation/json', ['Authorization' => 'Basic Zm9v'])
            ->assertUnauthorized();
    }
}
