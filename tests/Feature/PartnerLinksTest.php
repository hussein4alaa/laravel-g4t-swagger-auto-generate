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

    private function link(array $tags = ['Misc'], ?string $expires = null, array $operations = []): void
    {
        Settings::saveLinks([['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => $tags, 'operations' => $operations, 'expires' => $expires, 'created' => '2026-01-01']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerSpec(): array
    {
        return $this->getJson('/swagger/documentation/json?key='.self::TOKEN)->assertOk()->json();
    }

    /**
     * @return list<string>
     */
    private function operationIds(array $spec): array
    {
        $ids = [];
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                $ids[] = $operation['operationId'];
            }
        }
        sort($ids);

        return $ids;
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

    public function test_a_link_can_pick_single_endpoints_even_hidden_ones(): void
    {
        // Hidden from the public docs: one with the dashboard eye, one with ->hiddenDoc() in code.
        Settings::save([], ['hidden' => ['get_api_v1_stats']]);
        $this->link([], null, ['get_api_v1_secret', 'get_api_v1_stats', 'get_api_v1_ping']);

        $spec = $this->partnerSpec();

        $this->assertSame(['get_api_v1_ping', 'get_api_v1_secret', 'get_api_v1_stats'], $this->operationIds($spec));
        $this->assertSame(['Misc'], array_column($spec['tags'], 'name'), 'Only the sections of the picked endpoints');
        $this->assertStringNotContainsString('x-hidden-in-code', json_encode($spec));
        $this->assertArrayNotHasKey('PostResource', $spec['components']['schemas'] ?? [], 'Schemas of other sections do not leak');

        $public = $this->withBasicAuth('docs', 'docs-pass')->getJson('/swagger/documentation/json')->json();
        $this->assertArrayNotHasKey('/api/v1/secret', $public['paths'], 'The public docs still hide them');
        $this->assertArrayNotHasKey('/api/v1/stats', $public['paths']);
    }

    public function test_sections_show_their_visible_endpoints_and_picked_ones_add_to_them(): void
    {
        Settings::save([], ['hidden' => ['post_api_v1_posts']]);
        $this->link(['Post'], null, ['get_api_v1_ping']);

        $ids = $this->operationIds($this->partnerSpec());

        $this->assertContains('get_api_v1_posts', $ids);
        $this->assertContains('get_api_v1_ping', $ids);
        $this->assertNotContains('post_api_v1_posts', $ids, 'A whole section keeps its hidden endpoints hidden');
        $this->assertNotContains('get_api_v1_stats', $ids);
    }

    public function test_the_dashboard_lists_endpoints_hidden_in_code_and_saves_picked_ones(): void
    {
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $state = $session->getJson('/swagger/dashboard/api/settings')->assertOk()->json();
        $secret = collect($state['operations'])->firstWhere('id', 'get_api_v1_secret');
        $this->assertTrue($secret['hidden_in_code']);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme', 'tags' => [], 'operations' => ['get_api_v1_secret'], 'token' => self::TOKEN],
        ]])->assertOk();

        $this->assertSame(['get_api_v1_secret'], Settings::links()[0]['operations']);
        $this->assertSame(['get_api_v1_secret'], $this->operationIds($this->partnerSpec()));
    }
}
