<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class UiTest extends TestCase
{
    public function test_g4t_ui_is_the_default(): void
    {
        $this->get('/swagger/documentation')
            ->assertOk()
            ->assertSee('class="g4t-app"', false)
            ->assertSee('window.G4T_SETTINGS', false)
            ->assertDontSee('swagger-ui-bundle.js', false)
            ->assertDontSee('@scalar/api-reference', false);
    }

    public function test_g4t_ui_loads_nothing_from_a_cdn(): void
    {
        $content = $this->get('/swagger/documentation?ui=g4t')->getContent();

        $this->assertDoesNotMatchRegularExpression('#<script[^>]+src=#i', $content);
        $this->assertDoesNotMatchRegularExpression('#<link[^>]+href="https?://#i', $content);
    }

    public function test_g4t_ui_defaults_to_light_mode(): void
    {
        $this->get('/swagger/documentation')
            ->assertSee('data-mode="light"', false)
            ->assertSee('"mode":"light"', false)
            ->assertSee('"density":"comfortable"', false);
    }

    public function test_g4t_ui_mode_and_density_come_from_config(): void
    {
        config(['swagger.g4t.mode' => 'dark', 'swagger.g4t.density' => 'compact']);

        $this->get('/swagger/documentation')
            ->assertSee('data-mode="dark"', false)
            ->assertSee('data-density="compact"', false)
            ->assertSee('"mode":"dark"', false);

        config(['swagger.g4t.mode' => 'neon', 'swagger.g4t.density' => 'huge']);

        $this->get('/swagger/documentation')
            ->assertSee('"mode":"light"', false)
            ->assertSee('"density":"comfortable"', false);
    }

    public function test_g4t_ui_receives_versions_themes_and_json_url(): void
    {
        $content = $this->get('/swagger/documentation')->getContent();

        $this->assertStringContainsString('"jsonUrl":"http://localhost/swagger/documentation/json"', $content);
        $this->assertStringContainsString('"name":"v2","url":"http://localhost/swagger/documentation/json?version=v2"', $content);
        $this->assertStringContainsString('"defaultTheme":"default"', $content);
    }

    public function test_swagger_ui_is_still_available(): void
    {
        $this->get('/swagger/documentation?ui=swagger')
            ->assertOk()
            ->assertSee('swagger-ui-bundle.js', false);
    }

    public function test_scalar_ui_is_used_when_configured(): void
    {
        config(['swagger.ui' => 'scalar']);

        $this->get('/swagger/documentation')
            ->assertOk()
            ->assertSee('https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.73.0/dist/browser/standalone.js', false)
            ->assertSee('Scalar.createApiReference', false)
            ->assertDontSee('swagger-ui-bundle.js', false);
    }

    public function test_query_string_overrides_the_configured_ui(): void
    {
        config(['swagger.ui' => 'scalar']);
        $this->get('/swagger/documentation?ui=swagger')->assertSee('swagger-ui-bundle.js', false);

        config(['swagger.ui' => 'swagger']);
        $this->get('/swagger/documentation?ui=scalar')->assertSee('Scalar.createApiReference', false);

        $this->get('/swagger/documentation?ui=unknown')->assertSee('swagger-ui-bundle.js', false);

        config(['swagger.ui' => 'unknown']);
        $this->get('/swagger/documentation')->assertSee('class="g4t-app"', false);
    }

    public function test_scalar_version_must_be_plain_semver(): void
    {
        config(['swagger.ui' => 'scalar', 'swagger.scalar.version' => '1.0.0"><script>alert(1)</script>']);

        $this->get('/swagger/documentation')
            ->assertSee('@scalar/api-reference@1.73.0/', false)
            ->assertDontSee('alert(1)', false);
    }

    public function test_scalar_receives_every_version_as_a_source(): void
    {
        config(['swagger.ui' => 'scalar']);

        $content = $this->get('/swagger/documentation')->getContent();

        foreach (['all', 'v1', 'v2'] as $version) {
            $this->assertStringContainsString('json?version='.$version, $content);
        }
    }

    public function test_configured_default_theme_is_applied_to_swagger_ui(): void
    {
        config(['swagger.theme' => 'theme-monokai']);

        $this->get('/swagger/documentation?ui=swagger')->assertSee('"theme-monokai"', false);
        $this->get('/swagger/documentation?ui=g4t')->assertSee('"defaultTheme":"theme-monokai"', false);
    }

    public function test_login_button_is_hidden_without_a_login_url(): void
    {
        config(['swagger.auto_login.url' => null]);

        $this->get('/swagger/documentation?ui=swagger')->assertDontSee('g4t-login-toggle', false);
        // The g4t UI still offers a dialog to paste a token by hand.
        $this->get('/swagger/documentation?ui=g4t')->assertSee('"autoLogin":null', false);
    }

    public function test_g4t_ui_offers_manual_bearer_token_entry(): void
    {
        foreach ([null, '/api/v1/login'] as $loginUrl) {
            config(['swagger.auto_login.url' => $loginUrl]);

            $this->get('/swagger/documentation?ui=g4t')
                ->assertSee("data-action=\"open-token\"", false)
                ->assertSee('data-action="paste-token"', false)
                ->assertSee("'Change token'", false);
        }
    }

    public function test_g4t_ui_script_is_valid_javascript(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed.');
        }

        foreach ([null, '/api/v1/login'] as $loginUrl) {
            config(['swagger.auto_login.url' => $loginUrl]);
            preg_match_all('#<script>(.*?)</script>#s', $this->get('/swagger/documentation?ui=g4t')->getContent(), $scripts);
            $this->assertNotEmpty($scripts[1]);

            $file = tempnam(sys_get_temp_dir(), 'g4t').'.js';
            file_put_contents($file, implode("\n;\n", $scripts[1]));
            exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $output, $exitCode);
            @unlink($file);

            $this->assertSame(0, $exitCode, "The g4t UI script does not parse:\n".implode("\n", $output));
        }
    }

    public function test_g4t_ui_ships_code_samples_environments_history_and_download(): void
    {
        $this->get('/swagger/documentation?ui=g4t')
            ->assertSee('id="g4t-env-btn"', false)
            ->assertSee('data-action="toggle-download"', false)
            ->assertSee("['dart', 'Dart (Dio)']", false)
            ->assertSee("['php', 'PHP (Laravel)']", false)
            ->assertSee('data-action="toggle-history"', false)
            ->assertSee('data-action="reset-inputs"', false)
            ->assertSee('function toYaml(', false);
    }

    public function test_g4t_ui_receives_the_login_settings(): void
    {
        config(['swagger.auto_login.url' => '/api/v1/login']);

        $this->get('/swagger/documentation?ui=g4t')
            ->assertSee('"autoLogin":{"url":"http://localhost/api/v1/login","method":"POST"', false);
    }

    public function test_login_button_is_rendered_in_both_uis(): void
    {
        config(['swagger.auto_login.url' => '/api/v1/login']);

        foreach (['swagger', 'scalar'] as $ui) {
            $this->get('/swagger/documentation?ui='.$ui)
                ->assertSee('g4t-login-toggle', false)
                ->assertSee('window.G4TAuth', false)
                ->assertSee('"url":"http://localhost/api/v1/login"', false);
        }
    }

    public function test_unknown_security_scheme_falls_back_to_the_first_configured_one(): void
    {
        config([
            'swagger.auto_login.url' => '/api/v1/login',
            'swagger.auto_login.security_scheme' => 'missing',
        ]);

        $this->get('/swagger/documentation')->assertSee('"scheme":"bearerAuth"', false);
    }

    public function test_prefill_is_only_rendered_in_the_local_environment(): void
    {
        config([
            'swagger.auto_login.url' => '/api/v1/login',
            'swagger.auto_login.prefill' => ['email' => 'dev@example.test'],
        ]);

        $this->app['env'] = 'testing';
        $this->get('/swagger/documentation')->assertDontSee('dev@example.test', false);

        $this->app['env'] = 'local';
        $this->get('/swagger/documentation')->assertSee('dev@example.test', false);
    }
}
