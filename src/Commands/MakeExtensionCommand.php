<?php

namespace G4T\Swagger\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * php artisan make:swagger-extension SpatieData  →  app/Swagger/SpatieDataExtension.php
 */
class MakeExtensionCommand extends GeneratorCommand
{
    protected $name = 'make:swagger-extension';

    protected $description = 'Create a g4t/swagger extension class';

    protected $type = 'Swagger extension';

    protected function getStub(): string
    {
        return __DIR__.'/../stubs/extension.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Swagger';
    }

    protected function getNameInput(): string
    {
        $name = trim((string) $this->argument('name'));

        return str_ends_with($name, 'Extension') ? $name : $name.'Extension';
    }

    public function handle(): ?bool
    {
        $result = parent::handle();
        if ($result !== false) {
            $class = $this->qualifyClass($this->getNameInput());
            $this->line('');
            $this->line("  Register it in <comment>config/swagger.php</comment>: <info>\"extensions\" => [\\{$class}::class]</info>");
        }

        return $result;
    }
}
