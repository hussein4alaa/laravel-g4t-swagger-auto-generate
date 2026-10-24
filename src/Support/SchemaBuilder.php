<?php

namespace G4T\Swagger\Support;

use stdClass;

/**
 * Turns a FormRequest rules() array into OpenAPI request schemas and query parameters.
 *
 * Dot keys become real nesting: `address.city` → object, `tags.*` → array of scalars,
 * `items.*.product_id` → array of objects. Two shapes are produced:
 *  - jsonSchema(): nested schema for application/json;
 *  - formSchema(): flattened `a[b]` / `a[]` / `a[0][b]` properties for multipart/form-data and query strings,
 *    whose `required` list always uses the exact property names.
 */
final class SchemaBuilder
{
    /**
     * @var array{rules: ?FieldRules, children: array<string, array>, items: ?array}
     */
    private array $root;

    private bool $hasFiles = false;

    /**
     * @param  array<string, mixed>  $validations
     */
    public function __construct(array $validations)
    {
        $this->root = self::emptyNode();

        foreach ($this->withConfirmationFields($validations) as $key => $rules) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $fieldRules = $rules instanceof FieldRules ? $rules : FieldRules::parse($rules);
            if ($fieldRules->isFile()) {
                $this->hasFiles = true;
            }
            $this->insert(explode('.', $key), $fieldRules);
        }
    }

    public function hasFiles(): bool
    {
        return $this->hasFiles;
    }

    public function isEmpty(): bool
    {
        return $this->root['children'] === [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $extraProperties  appended as-is (e.g. `_method`)
     * @return array<string, mixed>
     */
    public function jsonSchema(array $extraProperties = []): array
    {
        $schema = $this->objectSchema($this->root);

        return $this->appendExtra($schema, $extraProperties);
    }

    /**
     * @param  array<string, array<string, mixed>>  $extraProperties
     * @return array<string, mixed>
     */
    public function formSchema(array $extraProperties = []): array
    {
        $properties = [];
        $required = [];
        foreach ($this->root['children'] as $name => $child) {
            $this->flatten($child, $name, true, $properties, $required);
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new stdClass : $properties];
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $this->appendExtra($schema, $extraProperties);
    }

    /**
     * Query parameters (GET / DELETE / HEAD). File fields are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public function queryParameters(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->root['children'] as $name => $child) {
            $this->flatten($child, $name, true, $properties, $required);
        }

        $parameters = [];
        foreach ($properties as $name => $schema) {
            if (($schema['format'] ?? null) === 'binary' || (($schema['items']['format'] ?? null) === 'binary')) {
                continue;
            }
            $description = $schema['description'] ?? null;
            unset($schema['description']);

            $parameter = [
                'name' => $name,
                'in' => 'query',
                'required' => in_array($name, $required, true),
                'schema' => $schema,
            ];
            if ($description !== null) {
                $parameter['description'] = $description;
            }
            if (($schema['type'] ?? null) === 'array') {
                $parameter['style'] = 'form';
                $parameter['explode'] = true;
            }
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $validations
     * @return array<string, mixed>
     */
    private function withConfirmationFields(array $validations): array
    {
        $out = [];
        foreach ($validations as $key => $rules) {
            $parsed = FieldRules::parse($rules);
            $out[$key] = $parsed;

            if ($parsed->has('confirmed') && is_string($key)) {
                $custom = $parsed->params('confirmed')[0] ?? null;
                $confirmationKey = $custom !== null && $custom !== '' ? $custom : $key.'_confirmation';
                if (! array_key_exists($confirmationKey, $validations)) {
                    $out[$confirmationKey] = $parsed->without(['confirmed', 'unique', 'exists']);
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $segments
     */
    private function insert(array $segments, FieldRules $rules): void
    {
        $node = &$this->root;
        foreach ($segments as $segment) {
            if ($segment === '*') {
                $node['items'] ??= self::emptyNode();
                $node = &$node['items'];

                continue;
            }
            $node['children'][$segment] ??= self::emptyNode();
            $node = &$node['children'][$segment];
        }
        $node['rules'] = $rules;
        unset($node);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeSchema(array $node, string $name): array
    {
        /** @var FieldRules|null $rules */
        $rules = $node['rules'];

        if ($node['items'] !== null || ($rules?->isArray() && $node['children'] === [])) {
            $schema = $rules?->toSchema($name) ?? [];
            $schema['type'] = 'array';
            $schema['items'] = $node['items'] !== null ? $this->nodeSchema($node['items'], $name) : ['type' => 'string'];

            return $schema;
        }

        if ($node['children'] !== []) {
            $schema = $this->objectSchema($node);
            if ($rules?->isNullable()) {
                $schema['nullable'] = true;
            }

            return $schema;
        }

        return $rules?->toSchema($name) ?? ['type' => 'string'];
    }

    /**
     * @return array<string, mixed>
     */
    private function objectSchema(array $node): array
    {
        $properties = [];
        $required = [];
        foreach ($node['children'] as $name => $child) {
            $properties[$name] = $this->nodeSchema($child, (string) $name);
            if (self::nodeIsRequired($child)) {
                $required[] = (string) $name;
            }
        }

        $schema = ['type' => 'object'];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['properties'] = $properties === [] ? new stdClass : $properties;

        return $schema;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    private function flatten(array $node, string $name, bool $parentRequired, array &$properties, array &$required): void
    {
        /** @var FieldRules|null $rules */
        $rules = $node['rules'];
        $isRequired = $parentRequired && (bool) $rules?->isRequired();

        if ($node['items'] !== null || ($rules?->isArray() && $node['children'] === [])) {
            $items = $node['items'];
            if ($items !== null && $items['children'] !== []) {
                // Array of objects: document the first element (`items[0][field]`).
                foreach ($items['children'] as $childName => $child) {
                    $this->flatten($child, "{$name}[0][{$childName}]", $isRequired, $properties, $required);
                }

                return;
            }

            $schema = $rules?->toSchema($name) ?? [];
            $schema['type'] = 'array';
            $schema['items'] = $items !== null ? $this->nodeSchema($items, $name) : ['type' => 'string'];
            unset($schema['format']);
            $properties["{$name}[]"] = $schema;
            if ($isRequired) {
                $required[] = "{$name}[]";
            }

            return;
        }

        if ($node['children'] !== []) {
            foreach ($node['children'] as $childName => $child) {
                $this->flatten($child, "{$name}[{$childName}]", $isRequired || ($parentRequired && $rules === null), $properties, $required);
            }

            return;
        }

        $properties[$name] = $rules?->toSchema(self::lastSegment($name)) ?? ['type' => 'string'];
        if ($isRequired) {
            $required[] = $name;
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $extraProperties
     * @return array<string, mixed>
     */
    private function appendExtra(array $schema, array $extraProperties): array
    {
        if ($extraProperties === []) {
            return $schema;
        }

        $properties = $schema['properties'] instanceof stdClass ? [] : $schema['properties'];
        foreach ($extraProperties as $name => $property) {
            $properties[$name] = $property;
            $schema['required'][] = $name;
        }
        $schema['properties'] = $properties;
        $schema['required'] = array_values(array_unique($schema['required']));

        return $schema;
    }

    /**
     * A field is required when its own rules say so, or — for a container without rules of its own
     * (`address.city` without an `address` rule) — when any of its children is required.
     */
    private static function nodeIsRequired(array $node): bool
    {
        if ($node['rules'] !== null) {
            return $node['rules']->isRequired();
        }

        foreach ($node['children'] as $child) {
            if (self::nodeIsRequired($child)) {
                return true;
            }
        }

        return false;
    }

    private static function lastSegment(string $name): string
    {
        return preg_match('/\[([^\[\]]+)\]$/', $name, $matches) ? $matches[1] : $name;
    }

    /**
     * @return array{rules: ?FieldRules, children: array<string, array>, items: ?array}
     */
    private static function emptyNode(): array
    {
        return ['rules' => null, 'children' => [], 'items' => null];
    }
}
