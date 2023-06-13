<?php

namespace G4T\Swagger;

use g4t\Pattern\GenerateRepo;
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
        $this->publishes([
            __DIR__ . '/config/swagger.php' => base_path('config/swagger.php'),
        ]);

        $this->publishes([
            __DIR__.'/custom-assets' => public_path('g4t/swagger'),
        ], 'public');
    
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