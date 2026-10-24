<?php

namespace G4T\Swagger\Commands;

use Illuminate\Console\Command;

class GenerateDocsCommand extends Command
{
    protected $signature = 'make:swagger';

    protected $description = 'Generate and cache the API documentation (alias of swagger:cache)';

    public function handle(): int
    {
        if (! config('swagger.enable')) {
            $this->error('Swagger is disabled (swagger.enable). Enable it to generate documentation.');

            return self::FAILURE;
        }

        return SwaggerCacheCommand::writeCache($this);
    }
}
