<?php

namespace G4T\Swagger\Commands;

use G4T\Swagger\Controllers\DocumentationController;
use Illuminate\Console\Command;

class SwaggerCacheCommand extends Command
{
    protected $signature = 'swagger:cache
                            {--clear : Remove the cached OpenAPI JSON file}';

    protected $description = 'Write OpenAPI JSON to the public cache file for faster Swagger UI (see config swagger.cached_spec_path)';

    public function handle(): int
    {
        $relative = config('swagger.cached_spec_path', 'doc.json');
        $path = public_path($relative);

        if ($this->option('clear')) {
            if (is_file($path)) {
                unlink($path);
                $this->info("Removed {$path}");
            } else {
                $this->comment("No file at {$path}");
            }

            return self::SUCCESS;
        }

        if (! config('swagger.enable', true)) {
            $this->error('Swagger is disabled (swagger.enable). Enable it to generate documentation.');

            return self::FAILURE;
        }

        $this->info('Generating OpenAPI JSON…');
        $doc = new DocumentationController;
        $jsonData = $doc->getSwaggerData();
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        if (file_put_contents($path, json_encode($jsonData, $flags)) === false) {
            $this->error("Could not write {$path}");

            return self::FAILURE;
        }
        $this->info("Written {$path}");

        return self::SUCCESS;
    }
}
