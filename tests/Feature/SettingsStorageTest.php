<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Support\Settings;
use G4T\Swagger\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;

/**
 * Dashboard settings on a shared filesystem disk (s3, …), for several servers or pods.
 */
class SettingsStorageTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::flush();
        parent::setUp();
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        @unlink(Settings::localPath());
        Settings::flush();
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('swagger.dashboard.username', 'admin');
        $app['config']->set('swagger.dashboard.password', 'secret-password');
        $app['config']->set('swagger.dashboard.settings_disk', 's3');
    }

    private function dashboard(): self
    {
        return $this->withSession(['g4t_swagger_dashboard_user' => 'admin']);
    }

    /**
     * What another server (or a restarted pod) reads: nothing kept in this process.
     */
    private function freshServer(): void
    {
        Settings::flush();
    }

    public function test_the_dashboard_saves_to_the_configured_disk(): void
    {
        $this->dashboard()->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Shared title']])
            ->assertOk()->assertJsonPath('storage.disk', 's3')->assertJsonPath('storage.locked', true);

        Storage::disk('s3')->assertExists('swagger-settings.json');
        $this->assertFileDoesNotExist(Settings::localPath(), 'Nothing is written on this server');
        $this->assertSame('Shared title', json_decode(Storage::disk('s3')->get('swagger-settings.json'), true)['config']['title']);
        $this->assertSame('s3:swagger-settings.json', Settings::path());
    }

    public function test_every_server_reads_the_settings_from_the_disk(): void
    {
        Storage::disk('s3')->put('swagger-settings.json', json_encode(['config' => ['title' => 'From the disk']]));
        Cache::flush();
        $this->freshServer();

        $this->assertSame('From the disk', Settings::overrides()['title']);
    }

    public function test_the_disk_is_read_through_the_cache_and_a_save_updates_it(): void
    {
        Storage::disk('s3')->put('swagger-settings.json', json_encode(['config' => ['title' => 'First']]));
        $this->freshServer();
        $this->assertSame('First', Settings::overrides()['title']);

        // Not asked again on every request.
        Storage::disk('s3')->put('swagger-settings.json', json_encode(['config' => ['title' => 'Changed elsewhere']]));
        $this->freshServer();
        $this->assertSame('First', Settings::overrides()['title']);

        // A save from the dashboard reaches the cache at once.
        Settings::save(['title' => 'Saved'], []);
        $this->freshServer();
        $this->assertSame('Saved', Settings::overrides()['title']);
    }

    public function test_a_disk_that_fails_never_breaks_the_app_or_the_docs(): void
    {
        config(['swagger.dashboard.settings_disk' => 'missing-disk']);
        $this->freshServer();

        $this->assertSame([], Settings::overrides());
        $this->get('/swagger/documentation/json')->assertOk();
        $this->dashboard()->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'x']])
            ->assertStatus(500)->assertJsonPath('message', 'Could not write missing-disk:swagger-settings.json. Check that its directory is writable.');
    }

    public function test_the_disk_set_in_the_config_cannot_be_changed_from_the_dashboard(): void
    {
        $this->dashboard()->putJson('/swagger/dashboard/api/storage', ['disk' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['disk']);
    }

    public function test_the_dashboard_moves_the_settings_to_a_disk_and_back(): void
    {
        config(['swagger.dashboard.settings_disk' => null]);
        $this->freshServer();
        $this->dashboard()->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Mine']])->assertOk();
        $this->assertFileExists(Settings::localPath());

        $this->dashboard()->putJson('/swagger/dashboard/api/storage', ['disk' => 's3'])
            ->assertOk()->assertJsonPath('storage.disk', 's3')->assertJsonPath('storage.locked', false);

        $this->assertSame('Mine', json_decode(Storage::disk('s3')->get('swagger-settings.json'), true)['config']['title']);
        $this->assertSame(['storage' => ['disk' => 's3']], json_decode(file_get_contents(Settings::localPath()), true), 'The local file only points to the disk');

        // A server with that small file (committed, or baked into the image) reads the disk.
        Cache::flush();
        $this->freshServer();
        $this->assertSame('s3', Settings::disk());
        $this->assertSame('Mine', Settings::overrides()['title']);

        // Later saves go to the disk.
        $this->dashboard()->putJson('/swagger/dashboard/api/settings', ['settings' => ['title' => 'Shared']])->assertOk();
        $this->assertSame('Shared', json_decode(Storage::disk('s3')->get('swagger-settings.json'), true)['config']['title']);
        $this->assertSame(['storage' => ['disk' => 's3']], json_decode(file_get_contents(Settings::localPath()), true));

        // And back to the local file.
        $this->dashboard()->putJson('/swagger/dashboard/api/storage', ['disk' => null])->assertOk()->assertJsonPath('storage.disk', null);
        $this->freshServer();
        $this->assertSame('Shared', json_decode(file_get_contents(Settings::localPath()), true)['config']['title']);
        $this->assertSame('Shared', Settings::overrides()['title']);
    }

    public function test_an_unknown_disk_is_rejected(): void
    {
        config(['swagger.dashboard.settings_disk' => null]);
        $this->freshServer();

        $this->dashboard()->putJson('/swagger/dashboard/api/storage', ['disk' => 'nowhere'])
            ->assertStatus(422)->assertJsonPath('errors.disk.0', 'There is no "nowhere" disk in config/filesystems.php.');
        $this->assertFileDoesNotExist(Settings::localPath());
    }

    public function test_a_disk_without_its_flysystem_adapter_says_what_to_install(): void
    {
        if (class_exists(PortableVisibilityConverter::class)) {
            $this->markTestSkipped('The S3 adapter is installed.');
        }
        config(['swagger.dashboard.settings_disk' => null, 'filesystems.disks.bucket' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'eu-central-1', 'bucket' => 'b']]);
        $this->freshServer();

        $this->dashboard()->putJson('/swagger/dashboard/api/storage', ['disk' => 'bucket'])
            ->assertStatus(422)
            ->assertJsonPath('errors.disk.0', 'The "bucket" disk needs the league/flysystem-aws-s3-v3 package: composer require league/flysystem-aws-s3-v3 "^3.0".');
    }
}
