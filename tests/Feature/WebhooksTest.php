<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Support\Settings;
use G4T\Swagger\Tests\Fixtures\Extensions\Data\UserData;
use G4T\Swagger\Tests\Fixtures\Models\Post;
use G4T\Swagger\Tests\Fixtures\Resources\PostResource;
use G4T\Swagger\Tests\TestCase;

class WebhooksTest extends TestCase
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
        $app['config']->set('swagger.webhooks', [
            [
                'name' => 'order.status_changed',
                'summary' => 'An order changed status',
                'description' => 'Sent when an order is paid or shipped.',
                'headers' => [['name' => 'X-Signature', 'description' => 'HMAC of the body', 'example' => 'sha256=abc']],
                'example' => ['event' => 'order.status_changed', 'data' => ['id' => 7, 'status' => 'paid', 'paid' => true]],
            ],
            'post.published' => ['class' => PostResource::class, 'method' => 'put', 'headers' => ['X-Event' => 'Always post.published']],
            ['name' => 'post.saved', 'class' => Post::class],
            ['name' => 'user.created', 'class' => UserData::class],
            ['name' => 'broken', 'class' => 'App\\Missing\\Payload'],
            ['summary' => 'no name: skipped'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function docs(string $query = ''): array
    {
        return $this->getJson('/swagger/documentation/json'.$query)->assertOk()->json();
    }

    public function test_webhooks_are_documented_as_x_webhooks(): void
    {
        $webhooks = $this->docs()['x-webhooks'];

        $this->assertSame(['order.status_changed', 'post.published', 'post.saved', 'user.created', 'broken'], array_keys($webhooks));
        $order = $webhooks['order.status_changed']['post'];
        $this->assertSame('An order changed status', $order['summary']);
        $this->assertSame('Sent when an order is paid or shipped.', $order['description']);
        $this->assertSame([['name' => 'X-Signature', 'in' => 'header', 'required' => true, 'description' => 'HMAC of the body', 'schema' => ['type' => 'string'], 'example' => 'sha256=abc']], $order['parameters']);
        $json = $order['requestBody']['content']['application/json'];
        $this->assertSame(['event' => 'order.status_changed', 'data' => ['id' => 7, 'status' => 'paid', 'paid' => true]], $json['example']);
        $this->assertSame('integer', $json['schema']['properties']['data']['properties']['id']['type']);
        $this->assertSame('boolean', $json['schema']['properties']['data']['properties']['paid']['type']);
        $this->assertArrayHasKey('200', $order['responses']);
        $this->assertArrayNotHasKey('/order.status_changed', $this->docs()['paths'], 'Webhooks are not paths');
    }

    public function test_a_payload_can_come_from_a_resource_a_model_or_a_dto(): void
    {
        $webhooks = $this->docs()['x-webhooks'];

        $resource = $webhooks['post.published']['put'];
        $this->assertSame('X-Event', $resource['parameters'][0]['name']);
        $this->assertSame(['id', 'title', 'body', 'is_featured', 'status', 'created_at'], array_keys($resource['requestBody']['content']['application/json']['example']));

        $model = $webhooks['post.saved']['post']['requestBody']['content']['application/json'];
        $this->assertSame(['id', 'created_at', 'updated_at'], array_keys($model['example']));
        $this->assertSame('object', $model['schema']['type']);

        $dto = $webhooks['user.created']['post']['requestBody']['content']['application/json'];
        $this->assertSame(['id', 'email', 'address', 'roles', 'joinedAt', 'active'], array_keys($dto['example']));
        $this->assertContains('email', $dto['schema']['required']);
        $this->assertStringNotContainsString('x-g4t', json_encode($webhooks), 'No internal markers leak');

        $this->assertArrayNotHasKey('requestBody', $webhooks['broken']['post'], 'A missing class documents no payload');
    }

    public function test_a_document_without_webhooks_has_no_x_webhooks(): void
    {
        config(['swagger.webhooks' => []]);

        $this->assertArrayNotHasKey('x-webhooks', $this->docs());
    }

    public function test_the_dashboard_saves_webhooks_and_validates_them(): void
    {
        $session = $this->withSession(self::SESSION);
        $webhook = ['name' => 'invoice.paid', 'summary' => 'Invoice paid', 'method' => 'POST', 'headers' => [['name' => 'X-Signature', 'example' => 'sha256=1']], 'example' => "{\n  \"id\": 5\n}"];

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['webhooks' => [$webhook]]])->assertOk()
            ->assertJsonPath('settings.webhooks.0.name', 'invoice.paid')
            ->assertJsonPath('settings.webhooks.0.example', ['id' => 5])
            ->assertJsonPath('overridden', ['webhooks']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['webhooks' => [$webhook + ['hidden' => true]]]])->assertOk()
            ->assertJsonPath('settings.webhooks.0.hidden', true);
        $this->assertArrayNotHasKey('x-webhooks', $this->docs(), 'A webhook hidden in its settings leaves the docs');
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['webhooks' => [$webhook]]])->assertOk();
        $this->assertSame(['invoice.paid'], array_keys($this->docs()['x-webhooks']));
        $this->assertSame(['id' => 5], $this->docs()['x-webhooks']['invoice.paid']['post']['requestBody']['content']['application/json']['example']);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['webhooks' => [
            ['name' => '1 bad name', 'method' => 'GET', 'example' => '{nope', 'headers' => [['name' => 'Bad Header']]],
            ['name' => 'dup'], ['name' => 'dup'],
        ]]])->assertStatus(422)->assertJsonValidationErrors(['webhooks.0.name', 'webhooks.0.method', 'webhooks.0.example', 'webhooks.0.headers.0.name', 'webhooks.1.name']);

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['webhooks' => [['name' => 'x', 'class' => 'App\\Http\\Resources\\X; exit']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['webhooks.0.class']);
    }

    public function test_a_partner_link_documents_all_some_or_none_of_the_webhooks(): void
    {
        $link = ['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => ['Misc']];

        Settings::saveLinks([$link]);
        $this->assertCount(5, $this->docs('?key='.self::TOKEN)['x-webhooks'], 'Default: every webhook');

        Settings::saveLinks([$link + ['webhooks' => ['post.saved', 'order.status_changed']]]);
        $this->assertSame(['order.status_changed', 'post.saved'], array_keys($this->docs('?key='.self::TOKEN)['x-webhooks']));

        Settings::saveLinks([$link + ['webhooks' => []]]);
        $this->assertArrayNotHasKey('x-webhooks', $this->docs('?key='.self::TOKEN));
    }

    public function test_the_dashboard_saves_the_webhooks_of_a_link(): void
    {
        $session = $this->withSession(self::SESSION);
        $link = ['id' => 'acme', 'name' => 'Acme', 'token' => self::TOKEN];

        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + ['webhooks' => ['post.saved']]]])
            ->assertOk()->assertJsonPath('links.0.webhooks', ['post.saved']);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + ['webhooks' => []]]])
            ->assertOk()->assertJsonPath('links.0.webhooks', []);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'links' => [$link + ['webhooks' => ['gone']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['links.0.webhooks.0']);
        // A webhook renamed in the same save can be picked under its new name.
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => ['webhooks' => [['name' => 'renamed']]], 'links' => [$link + ['webhooks' => ['renamed']]]])
            ->assertOk()->assertJsonPath('links.0.webhooks', ['renamed']);
    }

    public function test_webhooks_can_be_hidden_like_endpoints_and_picked_by_partner_links(): void
    {
        $session = $this->withSession(self::SESSION);
        $session->putJson('/swagger/dashboard/api/settings', ['settings' => [], 'layout' => ['hidden_webhooks' => ['post.saved']]])
            ->assertOk()->assertJsonPath('layout.hidden_webhooks', ['post.saved']);
        // Set after the save, which puts back the config file's values.
        config(['swagger.webhooks' => array_merge(config('swagger.webhooks'), [['name' => 'internal.audit', 'hidden' => true, 'example' => ['id' => 1]]])]);

        $public = array_keys($this->docs()['x-webhooks']);
        $this->assertNotContains('post.saved', $public, 'Hidden with the dashboard eye');
        $this->assertNotContains('internal.audit', $public, 'Hidden in the config');
        $this->assertContains('order.status_changed', $public);

        $link = ['id' => 'acme', 'name' => 'Acme', 'hash' => hash('sha256', self::TOKEN), 'tags' => ['Misc']];
        Settings::saveLinks([$link]);
        $this->assertNotContains('post.saved', array_keys($this->docs('?key='.self::TOKEN)['x-webhooks']), 'Default: the visible webhooks');

        Settings::saveLinks([$link + ['webhooks' => ['post.saved', 'internal.audit', 'order.status_changed']]]);
        $this->assertSame(['order.status_changed', 'post.saved', 'internal.audit'], array_keys($this->docs('?key='.self::TOKEN)['x-webhooks']), 'Picked ones show even when hidden');

        $session->getJson('/swagger/dashboard/api/settings')->assertOk()->assertJsonPath('layout.hidden_webhooks', ['post.saved']);
    }
}
