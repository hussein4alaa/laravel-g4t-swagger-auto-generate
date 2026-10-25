<?php

namespace G4T\Swagger;

use G4T\Swagger\Commands\GenerateDocsCommand;
use G4T\Swagger\Commands\PublishSwaggerMockServerCommand;
use G4T\Swagger\Commands\SwaggerCacheCommand;
use G4T\Swagger\Support\Settings;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;


class SwaggerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Defaults for keys missing from an older published config.
        $this->mergeConfigFrom(__DIR__.'/config/swagger.php', 'swagger');

        Route::macro('description', function ($description) {
            $this->action['description'] = $description;
            return $this;
        });

        Route::macro('summary', function ($summary) {
            $this->action['summary'] = $summary;
            return $this;
        });

        // ->deprecated('2026-12-31', 'POST /api/v2/tickets') / ->beta(): shown in the docs; the dashboard can override them.
        Route::macro('deprecated', function ($sunset = null, $replacement = null) {
            $this->action['g4t_status'] = array_filter(['status' => 'deprecated', 'sunset' => $sunset, 'replacement' => $replacement]);
            return $this;
        });

        Route::macro('beta', function () {
            $this->action['g4t_status'] = ['status' => 'beta'];
            return $this;
        });

        Route::macro('hiddenDoc', function () {
            $this->action['is_hidden'] = true;
            return $this;
        });

        $this->publishes([
            __DIR__.'/config/swagger.php' => config_path('swagger.php'),
        ], ['swagger-config', 'config']);

        $this->publishes([
            __DIR__.'/custom-assets' => public_path('g4t/swagger'),
        ], ['swagger-assets', 'public']);

        $this->commands([
            GenerateDocsCommand::class,
            SwaggerCacheCommand::class,
            PublishSwaggerMockServerCommand::class,
        ]);
    
    
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Values saved from the dashboard (storage/app/swagger/settings.json) override the config file.
        Settings::apply();

        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/views', 'swagger');

    }
}