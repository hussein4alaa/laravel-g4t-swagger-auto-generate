<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class CommandsTest extends TestCase
{
    /**
     * Where swagger:cache may write the document: storage/app/swagger (current default) or public/ (legacy).
     *
     * @return list<string>
     */
    private function candidatePaths(): array
    {
        $relative = config('swagger.cached_spec_path');

        return [storage_path('app/swagger/'.$relative), public_path($relative)];
    }

    private function cachedSpecPath(): ?string
    {
        foreach ($this->candidatePaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            foreach ($this->candidatePaths() as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    public function test_cache_command_writes_the_openapi_document(): void
    {
        $this->artisan('swagger:cache')->assertSuccessful();

        $this->assertNotNull($this->cachedSpecPath(), 'swagger:cache did not write a file');
        $cached = json_decode(file_get_contents($this->cachedSpecPath()), true);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('openapi', $cached);
        $this->assertArrayHasKey('/api/v1/posts', $cached['paths']);
    }

    public function test_cache_clear_removes_the_cached_document(): void
    {
        $this->artisan('swagger:cache')->assertSuccessful();
        $this->assertNotNull($this->cachedSpecPath());

        $this->artisan('swagger:cache', ['--clear' => true])->assertSuccessful();

        $this->assertNull($this->cachedSpecPath());
    }

    public function test_cached_document_is_served_when_enabled(): void
    {
        $this->artisan('swagger:cache')->assertSuccessful();
        $path = $this->cachedSpecPath();
        $cached = json_decode(file_get_contents($path), true);
        $cached['info']['title'] = 'From cache';
        file_put_contents($path, json_encode($cached));
        config(['swagger.use_cached_spec_when_present' => true]);

        $this->getJson('/swagger/documentation/json')->assertOk()->assertJsonPath('info.title', 'From cache');
    }

    public function test_cache_command_fails_when_swagger_is_disabled(): void
    {
        config(['swagger.enable' => false]);

        $this->artisan('swagger:cache')->assertFailed();
        $this->assertNull($this->cachedSpecPath());
    }
}
