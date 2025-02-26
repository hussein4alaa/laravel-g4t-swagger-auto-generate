<?php

namespace G4T\Swagger;

use g4t\Pattern\GenerateRepo;
use G4T\Swagger\Commands\GenerateDocsCommand;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;

class SwaggerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        Route::macro('description', function ($description) {
            $this->action['description'] = $description;
            return $this;
        });

        Route::macro('summary', function ($summary) {
            $this->action['summary'] = $summary;
            return $this;
        });

        Route::macro('hiddenDoc', function () {
            $this->action['is_hidden'] = true;
            return $this;
        });

        $this->publishes([
            __DIR__ . '/config/swagger.php' => base_path('config/swagger.php'),
        ]);

        $this->publishes([
            __DIR__ . '/custom-assets' => public_path('g4t/swagger'),
        ], 'public');

        $this->commands([
            GenerateDocsCommand::class,
        ]);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/views', 'swagger');
        app()->singleton('remote_data', function () {
            if(!config('swagger.remote_config')) {
                return $this->localConfig();
            }
            $response = $this->remoteInfo();
            if ($response['status']) {
                return $this->remoteConfig($response);
            } else {
                return $this->localConfig();
            }
        });
    }


    private function remoteConfig($response)
    {
        return [
            'name' => $response['data']['app']['name'],
            'auth_middlewares' => $response['data']['app']['auth_middlewares'],
            'servers' => $response['data']['servers'],
            'security' => $response['data']['security'],
            'versions' => array_merge(['all'], $response['data']['app']['versions']),
            'path' => $response['data']['app']['path'],
            'enable' => $response['data']['app']['is_enabled'],
            'default' => 'all',
            'mode' => 'remote',
        ];
    }

    private function localConfig()
    {
        return [
            'name' => config('swagger.title'),
            'auth_middlewares' => config('swagger.auth_middlewares'),
            'servers' => config('swagger.servers'),
            'security' => config('swagger.security_schemes'),
            'versions' => config('swagger.versions'),
            'path' => config('swagger.url'),
            'enable' => config('swagger.enable'),
            'default' => config('swagger.default'),
            'mode' => 'local',
        ];
    }

    public static function remoteInfo()
    {
        if(config('swagger.auth.type') == 'local') {
            return [
                'status' => false,
                'data' => []
            ];
        }
        try {
            $response = Http::get('http://swagger-domain/api/app', [
                'app_key' => env('APP_KEY')
            ]);
            if ($response->successful()) {
                return [
                    'status' => true,
                    'data' => $response->json()
                ];
            } else {
                return [
                    'status' => false,
                    'data' => []
                ];
            }
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'data' => []
            ];
        }
    }
}
