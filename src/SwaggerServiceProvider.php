<?php

namespace G4T\Swagger;

use G4T\Swagger\Commands\GenerateDocsCommand;
use G4T\Swagger\Commands\PublishSwaggerMockServerCommand;
use G4T\Swagger\Commands\SwaggerCacheCommand;
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
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/views', 'swagger');

    }
}