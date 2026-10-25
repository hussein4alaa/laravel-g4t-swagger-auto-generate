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
        $app['config']->set('swagger.security_schemes', [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
        ]);
        $app['config']->set('swagger.environments', [
            ['name' => 'Staging box', 'base_url' => 'https://staging.example.test'],
            ['name' => 'Internal box', 'base_url' => 'https://internal.example.test'],
        ]);
        $app['config']->set('swagger.auto_login', [
            ['name' => 'Customer login', 'url' => '/api/v1/login', 'security_scheme' => 'bearerAuth'],
            ['name' => 'Partner key login', 'url' => '/api/v1/key', 'security_scheme' => 'apiKey'],
        ]);
    }

    private function link(array $tags = ['Misc'], ?string $expires = null, array $operations = [], ?array $security = null, array $more = []): void
    {
        Settings::saveLinks([['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => $tags, 'operations' => $operations, 'security' => $security, 'expires' => $expires, 'created' => '2026-01-01'] + $more]);
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
        $this->get('/swagger/documentation?key=wrong-token-0123456789abcdef')->assertStatus(403);
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
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertStatus(410);
    }

    public function test_the_dashboard_stores_a_hash_and_an_encrypted_token(): void
    {
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme', 'tags' => ['Misc'], 'expires' => null, 'token' => self::TOKEN],
        ]])->assertOk()->assertJsonPath('links.0.name', 'Acme')
            ->assertJsonPath('links.0.token', self::TOKEN)
            ->assertJsonMissingPath('links.0.secret');

        $file = file_get_contents(Settings::path());
        $this->assertStringNotContainsString(self::TOKEN, $file);
        $this->assertStringContainsString(hash('sha256', self::TOKEN), $file);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertOk();

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme 2', 'tags' => [], 'hash' => hash('sha256', self::TOKEN)],
        ]])->assertOk()->assertJsonPath('links.0.name', 'Acme 2')->assertJsonPath('links.0.created', now()->toDateString())
            ->assertJsonPath('links.0.token', self::TOKEN, 'A kept link keeps its address');

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'other', 'name' => 'Other', 'hash' => str_repeat('a', 64)],
        ]])->assertStatus(422)->assertJsonValidationErrors(['links.0.token']);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => []])->assertOk();
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertStatus(403);
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

    public function test_a_link_takes_every_scheme_by_default(): void
    {
        $this->link(['Misc']);

        $spec = $this->partnerSpec();

        $this->assertSame(['bearerAuth', 'apiKey'], array_keys($spec['components']['securitySchemes']));
        $this->assertSame([['bearerAuth' => []], ['apiKey' => []]], $spec['paths']['/api/v1/profile']['get']['security']);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertSee('Customer login')->assertSee('Partner key login');
    }

    public function test_a_link_can_limit_the_docs_to_some_schemes(): void
    {
        $this->link(['Misc'], null, [], ['apiKey']);

        $spec = $this->partnerSpec();

        $this->assertSame(['apiKey'], array_keys($spec['components']['securitySchemes']));
        $this->assertSame([['apiKey' => []]], $spec['paths']['/api/v1/profile']['get']['security']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/api/v1/ping']['get'], 'Open endpoints stay open');
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertSee('Partner key login')->assertDontSee('Customer login');
    }

    public function test_a_link_whose_schemes_were_all_removed_falls_back_to_every_scheme(): void
    {
        $this->link(['Misc'], null, [], ['oldScheme']);

        $this->assertSame(['bearerAuth', 'apiKey'], array_keys($this->partnerSpec()['components']['securitySchemes']));
    }

    public function test_the_dashboard_saves_the_schemes_of_a_link(): void
    {
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $link = ['id' => 'acme', 'name' => 'Acme', 'tags' => ['Misc'], 'token' => self::TOKEN];

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + ['security' => ['unknown']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['links.0.security.0']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + ['security' => []]]])
            ->assertStatus(422)->assertJsonValidationErrors(['links.0.security']);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + ['security' => ['apiKey']]]])
            ->assertOk()->assertJsonPath('links.0.security', ['apiKey']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [['id' => 'acme', 'name' => 'Acme', 'tags' => ['Misc'], 'hash' => hash('sha256', self::TOKEN), 'security' => null]]])
            ->assertOk()->assertJsonPath('links.0.security', null);
    }

    /**
     * @return list<string>
     */
    private function paths(string $query = ''): array
    {
        return array_keys($this->getJson('/swagger/documentation/json?key='.self::TOKEN.$query)->assertOk()->json('paths'));
    }

    public function test_a_link_opens_every_version_by_default(): void
    {
        $this->link([]);

        $this->assertContains('/api/v1/ping', $this->paths('&version=v1'));
        $this->assertContains('/api/v2/products', $this->paths('&version=all'));
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertSee('"versions":[{"name":"all"', false);
    }

    public function test_a_link_limited_to_some_versions_never_opens_the_others(): void
    {
        $this->link([], null, [], null, ['versions' => ['v2']]);

        foreach (['', '&version=v2', '&version=v1', '&version=all'] as $query) {
            $this->assertSame(['/api/v2/products'], $this->paths($query), "Query: {$query}");
        }
        $this->get('/swagger/documentation?key='.self::TOKEN)
            ->assertSee('"versions":[{"name":"v2"', false)
            ->assertSee('"default":"v2"', false)
            ->assertDontSee('version=v1', false);
    }

    public function test_a_link_shows_all_some_or_none_of_the_shared_environments(): void
    {
        $this->link([]);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertSee('Staging box')->assertSee('Internal box');

        Settings::flush();
        $this->link([], null, [], null, ['environments' => ['Staging box']]);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertSee('Staging box')->assertDontSee('Internal box')->assertDontSee('internal.example.test');

        Settings::flush();
        $this->link([], null, [], null, ['environments' => []]);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertDontSee('Staging box')->assertDontSee('Internal box');

        $this->withBasicAuth('docs', 'docs-pass')->get('/swagger/documentation')->assertSee('Internal box', false);
    }

    public function test_the_dashboard_saves_the_versions_and_environments_of_a_link(): void
    {
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $link = ['id' => 'acme', 'name' => 'Acme', 'tags' => [], 'token' => self::TOKEN];
        $save = fn (array $more) => $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + $more]]);

        $save(['versions' => ['v9']])->assertStatus(422)->assertJsonValidationErrors(['links.0.versions.0']);
        $save(['versions' => []])->assertStatus(422)->assertJsonValidationErrors(['links.0.versions']);
        $save(['environments' => ['Nowhere']])->assertStatus(422)->assertJsonValidationErrors(['links.0.environments.0']);

        $save(['versions' => ['v1'], 'environments' => []])->assertOk()
            ->assertJsonPath('links.0.versions', ['v1'])
            ->assertJsonPath('links.0.environments', []);
        $save(['versions' => null, 'environments' => null])->assertOk()
            ->assertJsonPath('links.0.versions', null)
            ->assertJsonPath('links.0.environments', null);
    }

    public function test_a_new_address_replaces_the_old_one(): void
    {
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme', 'tags' => ['Misc'], 'token' => self::TOKEN],
        ]])->assertOk();

        $newToken = 'replacement-token-0123456789abcdef';
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [
            ['id' => 'acme', 'name' => 'Acme', 'tags' => ['Misc'], 'token' => $newToken, 'hash' => hash('sha256', self::TOKEN)],
        ]])->assertOk()->assertJsonPath('links.0.token', $newToken);

        $this->get('/swagger/documentation?key='.self::TOKEN)->assertStatus(403);
        $this->get('/swagger/documentation?key='.$newToken)->assertOk();
    }

    public function test_links_whose_address_cannot_be_read_still_open_the_docs(): void
    {
        $this->link();
        $session = $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
        $session->getJson('/swagger/dashboard/api/settings')->assertOk()->assertJsonPath('links.0.token', null);

        // Saved with another app key.
        Settings::flush();
        Settings::saveLinks([['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'secret' => 'not-an-encrypted-value', 'tags' => ['Misc']]]);
        $session->getJson('/swagger/dashboard/api/settings')->assertOk()->assertJsonPath('links.0.token', null);
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertOk();
    }

    public function test_a_wrong_or_expired_link_says_why(): void
    {
        $this->get('/swagger/documentation?key=wrong-token-0123456789abcdef')->assertStatus(403)
            ->assertSee('This link is not valid')->assertSee('هذا الرابط غير صالح')
            ->assertDontSee('Expected', false);
        $this->get('/swagger/documentation?key=cut')->assertStatus(403)->assertSee('This link is not valid');
        $this->getJson('/swagger/documentation/json?key=wrong-token-0123456789abcdef')->assertStatus(403)
            ->assertJsonPath('reason', 'invalid');

        $this->link([], '2026-01-31');
        $this->travelTo('2026-02-01');
        $this->get('/swagger/documentation?key='.self::TOKEN)->assertStatus(410)
            ->assertSee('This link has expired')->assertSee('2026-01-31')->assertSee('انتهت صلاحية هذا الرابط');
        $this->getJson('/swagger/documentation/json?key='.self::TOKEN)->assertStatus(410)
            ->assertJsonPath('reason', 'expired')
            ->assertJsonPath('message', 'This documentation link expired on 2026-01-31.');
    }

    public function test_without_a_key_the_docs_still_ask_for_the_password(): void
    {
        $this->get('/swagger/documentation')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->get('/swagger/documentation?key=')->assertStatus(401);
    }
}
