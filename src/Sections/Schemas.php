<?php

namespace G4T\Swagger\Sections;

use G4T\Swagger\Support\FieldRules;
use G4T\Swagger\Support\SchemaBuilder;

trait Schemas
{
    /**
     * Request body schema for the given rules (kept for backwards compatibility; see SchemaBuilder).
     *
     * @param  array<string, mixed>  $validations
     * @return array<string, mixed>
     */
    public function getSchemas(array $validations, string $name, string $method = 'POST'): array
    {
        $builder = new SchemaBuilder($validations);
        $extra = [];
        if ($builder->hasFiles() && $method !== 'POST') {
            $verb = explode('|', $method)[0];
            $extra['_method'] = ['type' => 'string', 'enum' => [$verb], 'default' => $verb];
        }

        $schema = $builder->hasFiles() ? $builder->formSchema($extra) : $builder->jsonSchema($extra);
        $schema['xml'] = ['name' => strtolower($name)];

        return $schema;
    }

    /**
     * OpenAPI schema of a single field's rules (string, array or rule objects).
     *
     * @return array<string, mixed>
     */
    public function getSwaggerInputSchema(mixed $validation_value, $type = 'string')
    {
        return FieldRules::parse($validation_value)->toSchema();
    }

    public function isRequiredRule($rule)
    {
        return FieldRules::parse($rule)->isRequired();
    }
}
