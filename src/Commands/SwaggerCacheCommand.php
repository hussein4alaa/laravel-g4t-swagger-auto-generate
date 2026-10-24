<?php

namespace G4T\Swagger\Commands;

use G4T\Swagger\Controllers\DocumentationController;
use G4T\Swagger\Support\CachedSpec;
use Illuminate\Console\Command;

class SwaggerCacheCommand extends Command
{
    protected $signature = 'swagger:cache
                            {--clear : Remove the cached OpenAPI JSON file}';

    protected $description = 'Cache the OpenAPI JSON for a faster Swagger UI (see config swagger.cached_spec_path)';

    public function handle(): int
    {
        if ($this->option('clear')) {
            $removed = CachedSpec::clear();
            foreach ($removed as $path) {
                $this->info("Removed {$path}");
            }
            if ($removed === []) {
                $this->comment('No cached OpenAPI file found.');
            }

            return self::SUCCESS;
        }

        if (! config('swagger.enable')) {
            $this->error('Swagger is disabled (swagger.enable). Enable it to generate documentation.');

            return self::FAILURE;
        }

        return self::writeCache($this);
    }

    /**
     * Shared by `swagger:cache` and the legacy `make:swagger`.
     */
    public static function writeCache(Command $command): int
    {
        $command->info('Generating OpenAPI JSON…');
        $json = json_encode((new DocumentationController)->getSwaggerData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || ! CachedSpec::write($json)) {
            $command->error('Could not write '.CachedSpec::path());

            return self::FAILURE;
        }
        $command->info('Written '.CachedSpec::path());

        $legacy = CachedSpec::legacyPublicPath();
        if (! CachedSpec::isPublic() && is_file($legacy)) {
            $command->warn("{$legacy} is publicly downloadable and bypasses the docs authentication. Delete it or run `php artisan swagger:cache --clear`.");
        }

        return self::SUCCESS;
    }
}
