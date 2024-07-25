<?php

namespace G4T\Swagger\Commands;

use G4T\Swagger\Controllers\DocumentationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GenerateDocsCommand extends Command
{
    protected $signature = 'make:swagger';

    protected $description = 'Generate API documentation for Swagger';

    public function handle()
    {
        $this->info('Generating API documentation...');
        $doc = new DocumentationController;
        $jsonData = $doc->getSwaggerData();
        $filePath = public_path('doc.json');
        file_put_contents($filePath, json_encode($jsonData, JSON_PRETTY_PRINT));
        $this->info('API documentation generated successfully.');
    }


}
