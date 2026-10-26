<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Support\Settings;
use G4T\Swagger\Tests\TestCase;

class FlowsTest extends TestCase
{
    private const SESSION = ['g4t_swagger_dashboard_user' => 'admin'];

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
    }

    /**
     * @return array<string, mixed>
     */
    private function flow(string $id, array $operations, array $more = []): array
    {
        return [
            'id' => $id,
            'name' => 'Flow '.$id,
            'description' => ['en' => 'Creates a post, then reads it.', 'ar' => 'ينشئ منشوراً ثم يقرأه.'],
            'steps' => array_map(fn (string $operation) => ['operation' => $operation, 'params' => [], 'headers' => [], 'body' => '', 'extract' => []], $operations),
        ] + $more;
    }

    /**
     * @return array<string, mixed>
     */
    private function docs(string $query = '?version=all'): array
    {
        return $this->getJson('/swagger/documentation/json'.$query)->assertOk()->json();
    }

    public function test_flows_reach_the_document_as_x_flows(): void
    {
        $flow = $this->flow('publish', ['post_api_v1_posts', 'get_api_v1_posts_post']);
        $flow['steps'][0]['body'] = '{"title": "Hello", "category_id": {{category}}}';
        $flow['steps'][0]['extract'] = [['var' => 'post_id', 'from' => 'body.data.id'], ['var' => 'request', 'from' => 'headers.x-request-id']];
        $flow['steps'][1]['params'] = ['post' => '{{post_id}}'];
        $flow['steps'][1]['headers'] = ['Accept-Language' => 'ar'];
        Settings::save([], ['flows' => [$flow]]);

        $flows = $this->docs()['x-flows'];

        $this->assertCount(1, $flows);
        $this->assertSame('publish', $flows[0]['id']);
        $this->assertSame(['en' => 'Creates a post, then reads it.', 'ar' => 'ينشئ منشوراً ثم يقرأه.'], $flows[0]['description']);
        $this->assertSame('{"title": "Hello", "category_id": {{category}}}', $flows[0]['steps'][0]['body']);
        $this->assertSame([['var' => 'post_id', 'from' => 'body.data.id'], ['var' => 'request', 'from' => 'headers.x-request-id']], $flows[0]['steps'][0]['extract']);
        $this->assertSame(['post' => '{{post_id}}'], $flows[0]['steps'][1]['params']);
        $this->assertSame(['Accept-Language' => 'ar'], $flows[0]['steps'][1]['headers']);
    }

    public function test_a_flow_needs_every_operation_it_uses(): void
    {
        Settings::save([], ['hidden' => ['get_api_v1_stats'], 'flows' => [
            $this->flow('visible', ['get_api_v1_ping']),
            $this->flow('hidden', ['get_api_v1_ping', 'get_api_v1_stats']),
            $this->flow('gone', ['get_api_v1_ping', 'delete_api_v9_nothing']),
            $this->flow('v2', ['get_api_v2_products']),
        ]]);

        $this->assertSame(['visible', 'v2'], array_column($this->docs()['x-flows'], 'id'));
        $this->assertSame(['visible'], array_column($this->docs('?version=v1')['x-flows'], 'id'), 'Flows of another version leave');
        $this->assertSame(['v2'], array_column($this->docs('?version=v2')['x-flows'], 'id'));

        Settings::save([], ['flows' => []]);
        $this->assertArrayNotHasKey('x-flows', $this->docs());
    }

    public function test_partner_links_only_see_flows_inside_the_link(): void
    {
        Settings::save([], ['hidden' => ['get_api_v1_stats'], 'flows' => [
            $this->flow('misc', ['get_api_v1_ping', 'get_api_v1_categories']),
            $this->flow('mixed', ['get_api_v1_ping', 'get_api_v1_posts']),
            $this->flow('picked', ['get_api_v1_stats']),
        ]]);
        Settings::saveLinks([['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => ['Misc'], 'operations' => ['get_api_v1_stats']]]);

        $this->assertSame(['misc', 'picked'], array_column($this->docs('?version=all&key='.self::TOKEN)['x-flows'], 'id'), 'A hidden endpoint the link picks keeps its flow');

        Settings::saveLinks([['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => ['Post']]]);
        $this->assertArrayNotHasKey('x-flows', $this->docs('?version=all&key='.self::TOKEN));
    }

    public function test_flows_can_be_hidden_like_endpoints_and_picked_by_partner_links(): void
    {
        Settings::save([], ['hidden_flows' => ['secret'], 'flows' => [
            $this->flow('open', ['get_api_v1_ping']),
            $this->flow('secret', ['get_api_v1_categories']),
            $this->flow('posts', ['get_api_v1_posts']),
        ]]);
        $this->assertSame(['open', 'posts'], array_column($this->docs()['x-flows'], 'id'), 'The eye hides a flow from the docs');

        $link = ['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => ['Misc']];
        Settings::saveLinks([$link]);
        $this->assertSame(['open'], array_column($this->docs('?version=all&key='.self::TOKEN)['x-flows'], 'id'), 'Default: the visible flows inside the link');

        Settings::saveLinks([$link + ['flows' => ['secret', 'posts']]]);
        $this->assertSame(['secret'], array_column($this->docs('?version=all&key='.self::TOKEN)['x-flows'], 'id'), 'A picked hidden flow shows; one with steps outside the link does not');

        Settings::saveLinks([$link + ['flows' => []]]);
        $this->assertArrayNotHasKey('x-flows', $this->docs('?version=all&key='.self::TOKEN));
    }

    public function test_the_dashboard_saves_the_flows_of_a_link(): void
    {
        $session = $this->withSession(self::SESSION);
        $link = ['id' => 'acme', 'name' => 'Acme', 'token' => self::TOKEN];
        $layout = ['flows' => [$this->flow('open', ['get_api_v1_ping'])], 'hidden_flows' => ['open']];

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => $layout, 'links' => [$link + ['flows' => ['open']]]])->assertOk()
            ->assertJsonPath('links.0.flows', ['open'])
            ->assertJsonPath('layout.hidden_flows', ['open']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['flows' => []], 'links' => [$link + ['flows' => ['open']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['links.0.flows.0']);
    }

    public function test_the_dashboard_saves_and_loads_flows(): void
    {
        $session = $this->withSession(self::SESSION);
        $flow = $this->flow('login', ['post_api_v1_posts']);
        $flow['steps'][0]['extract'] = [['var' => 'post_id', 'from' => 'status']];

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['flows' => [$flow]]])->assertOk()
            ->assertJsonPath('layout.flows.0.id', 'login')
            ->assertJsonPath('layout.flows.0.steps.0.extract.0.var', 'post_id');

        $state = $session->getJson('/swagger/dashboard/api/settings')->assertOk();
        $state->assertJsonPath('layout.flows.0.name', 'Flow login');
        $operations = collect($state->json('operations'))->keyBy('id');
        $this->assertSame([['name' => 'post', 'in' => 'path', 'required' => true]], $operations['get_api_v1_posts_post']['params'], 'The step editor gets the parameters');
        $this->assertContains('title', $operations['post_api_v1_posts']['body_fields']);
        $this->assertNull($operations['get_api_v1_ping']['body_fields']);
        $session->get('/swagger/dashboard/flows')->assertOk();
    }

    public function test_invalid_flows_are_rejected(): void
    {
        $session = $this->withSession(self::SESSION);
        $flow = $this->flow('bad id!', ['post_api_v1_unknown']);
        $flow['name'] = '';
        $flow['steps'][0]['body'] = '{"title": }';
        $flow['steps'][0]['headers'] = ['Bad Header' => 'x'];
        $flow['steps'][0]['extract'] = [['var' => '1abc', 'from' => 'cookies.x']];

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['flows' => [$flow, $this->flow('empty', [])]]])
            ->assertStatus(422)->assertJsonValidationErrors([
                'flows.0.id', 'flows.0.name', 'flows.0.steps.0.operation', 'flows.0.steps.0.body', 'flows.0.steps.0.headers',
                'flows.0.steps.0.extract.0.var', 'flows.0.steps.0.extract.0.from', 'flows.1.steps',
            ]);

        $good = $this->flow('ok', ['get_api_v1_ping']);
        $good['steps'][0]['body'] = '{"id": {{post_id}}, "token": "{{token}}"}';
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['flows' => [$good]]])->assertOk();
    }

    public function test_flows_read_from_the_file_keep_only_well_formed_values(): void
    {
        Settings::save([], ['flows' => [
            ['id' => 'ok', 'name' => '  ', 'steps' => [['operation' => 'get_api_v1_ping', 'params' => ['page' => 2, 'bad' => ['x']], 'extract' => [['var' => 'x', 'from' => 'nope'], ['var' => 'y', 'from' => 'body.a.0']]], 'not a step']],
            ['id' => 'ok', 'name' => 'Duplicate id'],
            'nope',
        ]]);

        $this->assertSame([[
            'id' => 'ok', 'name' => 'Flow', 'description' => ['en' => '', 'ar' => ''],
            'steps' => [['operation' => 'get_api_v1_ping', 'params' => ['page' => '2'], 'headers' => [], 'body' => '', 'extract' => [['var' => 'y', 'from' => 'body.a.0']]]],
        ]], Settings::layout()['flows']);
    }

    public function test_the_g4t_ui_ships_the_webhooks_and_flows_modules(): void
    {
        $this->get('/swagger/documentation')->assertOk()
            ->assertSee('GROUPS.push(', false)
            ->assertSee("ACTIONS['run-flow']", false)
            ->assertSee("ACTIONS['webhook-send']", false)
            ->assertSee("mode: 'no-cors'", false);
    }
}
