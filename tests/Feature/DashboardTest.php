<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Support\CachedSpec;
use G4T\Swagger\Support\Settings;
use G4T\Swagger\Tests\Fixtures\Controllers\V1\MiscController;
use G4T\Swagger\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class DashboardTest extends TestCase
{
    private const SESSION = ['g4t_swagger_dashboard_user' => 'admin'];

    protected function setUp(): void
    {
        Settings::flush();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink(Settings::path());
        @unlink(Settings::legacyPath());
        Settings::flush();
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('swagger.dashboard.username', 'admin');
        $app['config']->set('swagger.dashboard.password', 'secret-password');
    }

    public function test_guests_see_the_login_page(): void
    {
        $this->get('/swagger/dashboard')->assertOk()->assertSee('Sign in')->assertDontSee('window.G4T_DASHBOARD', false);
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        $this->post('/swagger/dashboard/login', ['username' => 'admin', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertSee('These credentials do not match')
            ->assertSessionMissing('g4t_swagger_dashboard_user');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/swagger/dashboard/login', ['username' => 'admin', 'password' => 'nope'])->assertStatus(422);
        }

        $this->post('/swagger/dashboard/login', ['username' => 'admin', 'password' => 'secret-password'])
            ->assertStatus(429)
            ->assertSee('Too many attempts');
    }

    public function test_correct_credentials_sign_in(): void
    {
        $this->post('/swagger/dashboard/login', ['username' => 'admin', 'password' => 'secret-password'])
            ->assertRedirect('/swagger/dashboard')
            ->assertSessionHas('g4t_swagger_dashboard_user', 'admin');
    }

    public function test_a_hashed_password_works(): void
    {
        config(['swagger.dashboard.password' => Hash::make('hashed-secret')]);

        $this->post('/swagger/dashboard/login', ['username' => 'admin', 'password' => 'hashed-secret'])
            ->assertSessionHas('g4t_swagger_dashboard_user', 'admin');
    }

    public function test_signed_in_users_see_the_dashboard_and_can_sign_out(): void
    {
        $this->withSession(self::SESSION)->get('/swagger/dashboard')->assertOk()->assertSee('window.G4T_DASHBOARD', false);
        $this->withSession(self::SESSION)->post('/swagger/dashboard/logout')->assertRedirect('/swagger/dashboard')->assertSessionMissing('g4t_swagger_dashboard_user');
    }

    public function test_dashboard_script_is_valid_javascript(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed.');
        }

        preg_match_all('#<script>(.*?)</script>#s', $this->withSession(self::SESSION)->get('/swagger/dashboard')->getContent(), $scripts);
        $this->assertNotEmpty($scripts[1]);

        $file = tempnam(sys_get_temp_dir(), 'g4t').'.js';
        file_put_contents($file, implode("\n;\n", $scripts[1]));
        exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $output, $exitCode);
        @unlink($file);

        $this->assertSame(0, $exitCode, "The dashboard script does not parse:\n".implode("\n", $output));
    }

    public function test_changing_the_dashboard_username_ends_sessions(): void
    {
        config(['swagger.dashboard.username' => 'someone-else']);

        $this->withSession(self::SESSION)->getJson('/swagger/dashboard/api/settings')->assertStatus(401);
    }

    public function test_the_api_requires_a_session(): void
    {
        $this->getJson('/swagger/dashboard/api/settings')->assertStatus(401);
        $this->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'x']])->assertStatus(401);
        $this->get('/swagger/dashboard/api/settings')->assertRedirect('/swagger/dashboard');
    }

    public function test_settings_lists_values_and_every_operation(): void
    {
        $state = $this->withSession(self::SESSION)->getJson('/swagger/dashboard/api/settings')->assertOk()->json();

        $this->assertSame('Api Documentation', $state['settings']['title']);
        $this->assertArrayNotHasKey('password', $state['settings']);
        $this->assertArrayHasKey('password_set', $state['settings']);
        $this->assertSame([], $state['overridden']);
        $ids = array_column($state['operations'], 'id');
        $this->assertContains('get_api_v1_posts', $ids);
        $this->assertContains('get_api_v2_products', $ids, 'All versions are listed');
    }

    public function test_saving_overrides_only_changed_values(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [
            'title' => 'Shop API',
            'description' => config('swagger.description'),
            'ui' => 'scalar',
        ]])->assertOk()->assertJsonPath('settings.title', 'Shop API');

        $saved = json_decode(file_get_contents(Settings::path()), true);
        $this->assertSame(['title' => 'Shop API', 'ui' => 'scalar'], $saved['config']);
        $this->assertSame('Shop API', $this->spec()['info']['title']);
        $this->get('/swagger/documentation')->assertSee('Scalar.createApiReference', false);
    }

    public function test_saving_the_config_file_value_removes_the_override(): void
    {
        $session = $this->withSession(self::SESSION);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Shop API']])->assertOk();
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Api Documentation']])
            ->assertOk()
            ->assertJsonPath('overridden', []);
    }

    public function test_settings_are_saved_in_the_project_root(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Root']])->assertOk();

        $this->assertSame(base_path('swagger-settings.json'), Settings::path());
        $this->assertFileExists(base_path('swagger-settings.json'));
    }

    public function test_settings_from_the_old_storage_location_move_on_the_next_save(): void
    {
        @mkdir(dirname(Settings::legacyPath()), 0755, true);
        file_put_contents(Settings::legacyPath(), json_encode(['config' => ['title' => 'Legacy'], 'layout' => []]));
        Settings::flush();
        Settings::apply();
        $this->assertSame('Legacy', config('swagger.title'));

        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => ['version' => '9.9.9']])->assertOk();

        $this->assertFileDoesNotExist(Settings::legacyPath());
        $this->assertSame(['title' => 'Legacy', 'version' => '9.9.9'], json_decode(file_get_contents(Settings::path()), true)['config']);
    }

    public function test_settings_survive_a_new_application_boot(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Persisted']])->assertOk();

        // A fresh boot: the config file value again, then the saved file applied over it.
        Settings::flush();
        config(['swagger.title' => 'Api Documentation']);
        Settings::apply();

        $this->assertSame('Persisted', config('swagger.title'));
        $this->assertSame('Persisted', $this->spec()['info']['title']);
    }

    public function test_security_schemes_and_global_headers_can_be_saved(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [
            'security_schemes' => [
                'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            ],
            'global_headers' => [['name' => 'Accept-Language', 'required' => false, 'enum' => ['en', 'ar']]],
        ]])->assertOk();

        $spec = $this->spec();
        $this->assertSame(['apiKey', 'bearerAuth'], array_keys($spec['components']['securitySchemes']));
        $headers = array_values(array_filter($spec['paths']['/api/v1/posts']['get']['parameters'], fn (array $p) => $p['in'] === 'header'));
        $this->assertSame('Accept-Language', $headers[0]['name']);
        $this->assertSame(['en', 'ar'], $headers[0]['schema']['enum']);
    }

    public function test_shared_environments_are_saved_and_reach_the_g4t_ui(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [
            'environments' => [[
                'name' => 'Staging',
                'base_url' => 'https://staging.example.com',
                'variables' => [['key' => 'tenant', 'value' => 'acme']],
                'headers' => [['key' => 'X-Tenant', 'value' => '{{tenant}}']],
            ]],
        ]])->assertOk()->assertJsonPath('settings.environments.0.variables', ['tenant' => 'acme']);

        $this->assertSame([[
            'name' => 'Staging',
            'base_url' => 'https://staging.example.com',
            'variables' => ['tenant' => 'acme'],
            'headers' => ['X-Tenant' => '{{tenant}}'],
        ]], json_decode(file_get_contents(Settings::path()), true)['config']['environments']);

        $this->get('/swagger/documentation?ui=g4t')
            ->assertSee('"name":"Staging"', false)
            ->assertSee('"baseUrl":"https://staging.example.com"', false)
            ->assertSee('"vars":[{"key":"tenant","value":"acme"}]', false)
            ->assertSee('"headers":[{"key":"X-Tenant","value":"{{tenant}}"}]', false);
    }

    public function test_invalid_environments_are_rejected(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [
            'environments' => [
                ['name' => 'Staging', 'base_url' => 'not a url', 'variables' => [['key' => 'bad name', 'value' => 'x']], 'headers' => [['key' => 'Bad Header', 'value' => 'x']]],
                ['name' => 'Staging'],
            ],
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'environments.0.name', 'environments.0.base_url', 'environments.0.variables.0.key', 'environments.0.headers.0.key',
        ]);
    }

    public function test_auth_middlewares_mark_routes_as_protected(): void
    {
        $this->app['router']->middleware(['api', 'jwt.auth'])->get('api/v1/guarded', [MiscController::class, 'ping']);
        $this->assertArrayNotHasKey('security', $this->spec()['paths']['/api/v1/guarded']['get']);

        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [
            'auth_middlewares' => ['auth', 'jwt.auth'],
        ]])->assertOk()->assertJsonPath('settings.auth_middlewares', ['auth', 'jwt.auth']);

        $operation = $this->spec()['paths']['/api/v1/guarded']['get'];
        $this->assertArrayHasKey('security', $operation);
        $this->assertArrayHasKey('401', $operation['responses']);

        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => ['auth_middlewares' => ['has space', 'x', 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors(['auth_middlewares.0', 'auth_middlewares.1']);
    }

    public function test_invalid_values_are_rejected_with_field_errors(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [
            'ui' => 'nope',
            'versions' => ['all', 'v1'],
            'default' => 'v9',
            'servers' => [['url' => 'not a url']],
            'security_schemes' => ['key' => ['type' => 'apiKey', 'in' => 'header']],
            'global_headers' => [['name' => 'Bad Header']],
        ]])->assertStatus(422)->assertJsonValidationErrors(['ui', 'default', 'servers.0.url', 'security_schemes.key.name', 'global_headers.0.name']);

        $this->assertFileDoesNotExist(Settings::path());
    }

    public function test_layout_hides_and_orders_operations(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', [
            'settings' => [],
            'layout' => [
                'tags' => ['Misc', 'Post'],
                'operations' => ['Post' => ['delete_api_v1_posts_post', 'get_api_v1_posts']],
                'hidden' => ['post_api_v1_posts'],
            ],
        ])->assertOk();

        $spec = $this->spec();
        $tags = array_column($spec['tags'], 'name');
        $this->assertSame(['Misc', 'Post'], array_slice($tags, 0, 2));
        $this->assertArrayNotHasKey('post', $spec['paths']['/api/v1/posts'], 'Hidden operations leave the document');

        // OpenAPI groups operations by path; the exact order is carried by x-order.
        $postOperations = [];
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                if (($operation['tags'][0] ?? null) === 'Post') {
                    $postOperations[$operation['x-order']] = $operation['operationId'];
                }
            }
        }
        ksort($postOperations);
        $this->assertSame(['delete_api_v1_posts_post', 'get_api_v1_posts'], array_slice(array_values($postOperations), 0, 2), 'Listed operations come first, in the saved order');

        $state = $this->withSession(self::SESSION)->getJson('/swagger/dashboard/api/settings')->json();
        $postIds = array_column(array_values(array_filter($state['operations'], fn (array $o) => $o['tag'] === 'Post')), 'id');
        $this->assertSame(['delete_api_v1_posts_post', 'get_api_v1_posts'], array_slice($postIds, 0, 2), 'The dashboard lists operations in the saved order');
        $hidden = array_values(array_filter($state['operations'], fn (array $o) => $o['hidden']));
        $this->assertSame(['post_api_v1_posts'], array_column($hidden, 'id'), 'The dashboard still lists hidden operations');
    }

    public function test_a_whole_controller_can_be_hidden(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', [
            'settings' => [],
            'layout' => ['hidden_tags' => ['Post']],
        ])->assertOk()->assertJsonPath('layout.hidden_tags', ['Post']);

        $spec = $this->spec();
        $this->assertNotContains('Post', array_column($spec['tags'], 'name'));
        $this->assertArrayNotHasKey('/api/v1/posts', $spec['paths']);
        $this->assertArrayNotHasKey('/api/v1/posts/{post}', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/ping', $spec['paths']);

        $state = $this->withSession(self::SESSION)->getJson('/swagger/dashboard/api/settings')->json();
        $this->assertContains('get_api_v1_posts', array_column($state['operations'], 'id'), 'The dashboard still lists the hidden controller');

        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['hidden_tags' => []]])->assertOk();
        $this->assertArrayHasKey('/api/v1/posts', $this->spec()['paths']);
    }

    public function test_english_and_arabic_texts_reach_the_document(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', [
            'settings' => [],
            'layout' => ['texts' => [
                'info' => ['ar' => ['title' => 'توثيق الواجهة', 'description' => '']],
                'tags' => ['Post' => ['en' => 'Blog posts', 'ar' => 'المقالات']],
                'operations' => ['get_api_v1_posts' => ['en' => ['summary' => 'All posts'], 'ar' => ['summary' => 'كل المقالات', 'description' => 'قائمة مرتبة']]],
            ]],
        ])->assertOk()->assertJsonPath('layout.texts.info', ['ar' => ['title' => 'توثيق الواجهة']]);

        $spec = $this->spec();
        $operation = $spec['paths']['/api/v1/posts']['get'];
        $this->assertSame('All posts', $operation['summary'], 'English replaces the generated summary');
        $this->assertSame(['ar' => ['summary' => 'كل المقالات', 'description' => 'قائمة مرتبة']], $operation['x-translations']);
        $post = collect($spec['tags'])->firstWhere('name', 'Post');
        $this->assertSame('Blog posts', $post['description']);
        $this->assertSame(['ar' => ['description' => 'المقالات']], $post['x-translations']);
        $this->assertSame(['ar' => ['title' => 'توثيق الواجهة']], $spec['info']['x-translations']);
        $this->assertSame('Api Documentation', $spec['info']['title']);

        $state = $this->withSession(self::SESSION)->getJson('/swagger/dashboard/api/settings')->json();
        $listed = collect($state['operations'])->firstWhere('id', 'get_api_v1_posts');
        $this->assertNotSame('All posts', $listed['summary'], 'The dashboard shows the generated summary as the placeholder');
        $this->assertArrayHasKey('Post', $state['defaults']['tags']);
    }

    public function test_several_logins_can_be_saved(): void
    {
        $session = $this->withSession(self::SESSION);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['auto_login' => [
            ['name' => 'Customer', 'url' => '/api/v1/login', 'method' => 'POST', 'fields' => ['email' => 'Email', 'password' => 'Password'], 'token_path' => '', 'security_scheme' => 'bearerAuth'],
            ['name' => 'Admin', 'url' => '/api/admin/login', 'method' => 'POST', 'fields' => ['username' => 'Username'], 'security_scheme' => 'bearerAuth'],
        ]]])->assertOk()->assertJsonCount(2, 'settings.auto_login');

        $this->assertSame(['Customer', 'Admin'], array_column(config('swagger.auto_login'), 'name'), 'A list replaces the config value instead of merging into it');
        $this->assertArrayNotHasKey('token_path', config('swagger.auto_login')[0], 'Empty values are not stored');

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['auto_login' => [['name' => 'Customer'], ['name' => 'Customer', 'url' => '/x', 'fields' => ['bad name' => 'x']]]]])
            ->assertStatus(422)->assertJsonValidationErrors(['auto_login.0.url', 'auto_login.1.name', 'auto_login.1.fields']);
    }

    public function test_status_and_note_reach_every_ui(): void
    {
        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', [
            'settings' => [],
            'layout' => ['meta' => ['operations' => [
                'get_api_v1_posts' => ['status' => 'deprecated', 'sunset' => '2026-12-31', 'replacement' => 'GET /api/v2/products', 'note' => 'Sorted by date.'],
                'get_api_v1_ping' => ['status' => 'beta', 'sunset' => '2026-01-01'],
                'get_api_v1_stats' => ['status' => 'nope', 'sunset' => 'soon'],
            ]]],
        ])->assertOk()->assertJsonPath('layout.meta.operations.get_api_v1_ping', ['status' => 'beta']);

        $spec = $this->spec();
        $posts = $spec['paths']['/api/v1/posts']['get'];
        $this->assertTrue($posts['deprecated']);
        $this->assertSame(['deprecated', '2026-12-31', 'GET /api/v2/products', 'Sorted by date.'], [$posts['x-status'], $posts['x-sunset'], $posts['x-replaced-by'], $posts['x-note']]);
        $this->assertStringContainsString('**Deprecated**: removed on 2026-12-31. Use GET /api/v2/products instead.', $posts['description'], 'Swagger UI and Scalar read it from the description');
        $this->assertStringNotContainsString('Deprecated', $posts['x-description']);

        $ping = $spec['paths']['/api/v1/ping']['get'];
        $this->assertSame('beta', $ping['x-status']);
        $this->assertArrayNotHasKey('deprecated', $ping);
        $this->assertArrayNotHasKey('x-status', $spec['paths']['/api/v1/stats']['get'], 'Unknown statuses are dropped');
    }

    public function test_the_dashboard_replaces_a_status_set_in_code(): void
    {
        $this->app['router']->middleware('api')->get('api/v1/old', [MiscController::class, 'ping'])->deprecated('2026-06-30', 'GET /api/v1/ping');
        $old = fn () => $this->spec()['paths']['/api/v1/old']['get'];
        $this->assertSame(['deprecated', '2026-06-30'], [$old()['x-status'], $old()['x-sunset']]);

        $state = $this->withSession(self::SESSION)->getJson('/swagger/dashboard/api/settings')->json();
        $this->assertSame(['status' => 'deprecated', 'sunset' => '2026-06-30', 'replacement' => 'GET /api/v1/ping'], collect($state['operations'])->firstWhere('id', 'get_api_v1_old')['code_status']);

        $save = fn (array $meta) => $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['meta' => ['operations' => ['get_api_v1_old' => $meta]]]])->assertOk();
        $save(['note' => 'Only a note']);
        $this->assertSame(['deprecated', '2026-06-30', 'Only a note'], [$old()['x-status'], $old()['x-sunset'], $old()['x-note']], 'A note alone keeps the status from code');

        $save(['status' => 'stable']);
        $this->assertArrayNotHasKey('deprecated', $old());
        $this->assertArrayNotHasKey('x-sunset', $old(), 'Stable clears the deprecation set in code');
    }

    public function test_an_empty_password_keeps_the_docs_password(): void
    {
        // As if config/swagger.php had this password when the app booted.
        config(['swagger.password' => 'docs-pass']);
        Settings::flush();
        Settings::apply();
        $session = $this->withSession(self::SESSION);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['enable_auth' => true, 'password' => '']])->assertOk();
        $this->assertSame('docs-pass', config('swagger.password'));

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['password' => 'new-pass']])->assertOk()->assertJsonPath('settings.password_set', true);
        $this->assertSame('new-pass', config('swagger.password'));
    }

    public function test_reset_removes_every_dashboard_change(): void
    {
        $session = $this->withSession(self::SESSION);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Shop API'], 'layout' => ['hidden' => ['get_api_v1_ping']]])->assertOk();

        $session->deleteJson('/swagger/dashboard/api/settings')->assertOk()->assertJsonPath('settings.title', 'Api Documentation');

        $this->assertFileDoesNotExist(Settings::path());
        $this->assertArrayHasKey('/api/v1/ping', $this->spec()['paths']);
    }

    public function test_saving_clears_the_cached_document(): void
    {
        $this->artisan('swagger:cache')->assertExitCode(0);
        $this->assertNotNull(CachedSpec::read());

        $this->withSession(self::SESSION)->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Fresh']])->assertOk();

        $this->assertNull(CachedSpec::read());
    }
}
