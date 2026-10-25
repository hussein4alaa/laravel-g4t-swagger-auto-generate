<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Support\Settings;
use G4T\Swagger\Tests\TestCase;

class PartnerLinksTest extends TestCase
{
    private const TOKEN = 'partner-token-0123456789abcdefXYZ';

    protected function setUp(): void
    {
        Settings::flush();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink(Settings::path());
        Settings::flush();
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('swagger.dashboard.username', 'admin');
        $app['config']->set('swagger.dashboard.password', 'secret-password');
        // Basic Auth on the docs, as the config file would set it.
        $app['config']->set('swagger.enable_auth', true);
        $app['config']->set('swagger.username', 'docs');
        $app['config']->set('swagger.password', 'docs-pass');
    }

    private function link(array $tags = ['Misc'], ?string $expires = null): void
    {
        Settings::saveLinks([['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => $tags, 'expires' => $expires, 'created' => '2026-01-01']]);
    }

    public function test_a_link_opens_the_docs_without_the_password(): void
    {
        $this->link();

        $this->get('/swagger/documentation')->assertStatus(401);
        $this->get('/swagger/documentation?key=wrong-token-0123456789abcdef')->assertStatus(401);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertOk()
            ->assertSee('shared with Acme', false)
            ->assertSee('"partner":{"name":"Acme"', false)
            ->assertSee('json?version=all\\u0026key='.self::TOKEN, false);
    }

    public function test_a_link_only_shows_its_sections_and_their_schemas(): void
    {
        $this->link(['Misc']);

        $spec = $this->getJson('/swagger/documentation/json?version=all&key='.self::TOKEN)->assertOk()->json();
        $this->assertSame(['Misc'], array_column($spec['tags'], 'name'));
        $this->assertArrayNotHasKey('/api/v1/posts', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/ping', $spec['paths']);
        $schemas = array_keys($spec['components']['schemas'] ?? []);
        $this->assertContains('Category', $schemas, 'Schemas the section uses stay');
        $this->assertNotContains('Post', $schemas, 'Schemas of other sections are removed');

        $this->getJson('/swagger/documentation/json?version=all')->assertStatus(401);
    }

    public function test_a_link_works_with_the_docs_turned_off_and_stops_after_it_expires(): void
    {
        config(['swagger.enable' => false]);
        $this->link([], now()->addDay()->toDateString());
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertOk();
        $this->get('/swagger/documentation?ui=swagger&key='.self::TOKEN)->assertOk()->assertSee('window.G4T_SETTINGS', false);

        Settings::flush();
        $this->link([], now()->subDay()->toDateString());
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertStatus(401);
    }

    public function test_the_dashboard_stores_only_a_hash_of_the_token(): void
    {
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme', 'tags' => ['Misc'], 'expires' => null, 'token' => self::TOKEN],
        ]])->assertOk()->assertJsonPath('links.0.name', 'Acme')->assertJsonMissingPath('links.0.token');

        $file = file_get_contents(Settings::path());
        $this->assertStringNotContainsString(self::TOKEN, $file);
        $this->assertStringContainsString(hash('sha256', self::TOKEN), $file);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertOk();

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme 2', 'tags' => [], 'hash' => hash('sha256', self::TOKEN)],
        ]])->assertOk()->assertJsonPath('links.0.name', 'Acme 2')->assertJsonPath('links.0.created', now()->toDateString());

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'other', 'name' => 'Other', 'hash' => str_repeat('a', 64)],
        ]])->assertStatus(422)->assertJsonValidationErrors(['links.0.token']);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => []])->assertOk();
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertStatus(401);
    }
}
