<?php

namespace G4T\Swagger\Inference;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Turns inferred response examples into OpenAPI schemas.
 *
 * Resource and model examples carry a hidden {@see self::MARKER} key naming the class that produced
 * them. Every marked object becomes a reusable component under #/components/schemas and is referenced
 * with $ref; everything else becomes an inline schema typed from the example values.
 */
class ResponseSchema
{
    /** Hidden key added to resource/model example arrays; never part of the published example. */
    public const MARKER = "\0g4t:class";

    /** @var array<string, string> class => component name */
    private array $names = [];

    /** @var array<string, string> component name => class */
    private array $owners = [];

    /**
     * Observed shapes per component, merged into one schema when the document is built.
     *
     * @var array<string, array{properties: array<string, array<string, mixed>>, seen: int, keyCounts: array<string, int>}>
     */
    private array $components = [];

    /** @var list<string> names already used elsewhere in components.schemas (request bodies) */
    private array $reserved = [];

    /**
     * @param  (\Closure(string, string): (list<int|string>|null))|null  $enumValues  allowed values of a property
     *         of a marked class (resource or model), e.g. from an enum cast
     */
    public function __construct(private ?\Closure $enumValues = null)
    {
    }

    /**
     * @param  list<string>  $names
     */
    public function reserve(array $names): void
    {
        $this->reserved = array_values(array_unique(array_merge($this->reserved, $names)));
    }

    /**
     * The example as it is published: markers removed at every level.
     */
    public static function strip(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value[self::MARKER]);
        foreach ($value as $key => $item) {
            $value[$key] = self::strip($item);
        }

        return $value;
    }

    /**
     * Schema for an example; marked objects are registered as components and referenced.
     *
     * @return array<string, mixed>
     */
    public function schemaFor(mixed $example): array
    {
        if (is_array($example) && isset($example[self::MARKER]) && is_string($example[self::MARKER])) {
            return $this->reference($example[self::MARKER], $example);
        }

        if (is_array($example)) {
            if ($example === []) {
                return ['type' => 'array', 'items' => new \stdClass];
            }
            if (array_is_list($example)) {
                return ['type' => 'array', 'items' => $this->itemsSchema($example)];
            }

            if ($example === ExampleGuesser::OBJECT_PLACEHOLDER) {
                // Placeholder for an array/json cast whose keys are unknown: free-form object.
                return ['type' => 'object', 'additionalProperties' => true];
            }

            $properties = [];
            foreach ($example as $key => $value) {
                $properties[(string) $key] = $this->schemaFor($value);
            }

            return ['type' => 'object', 'properties' => $properties];
        }

        return self::scalarSchema($example);
    }

    /**
     * Inline schema for a plain payload (no components), e.g. error responses or stored examples.
     *
     * @return array<string, mixed>
     */
    public static function plain(mixed $example): array
    {
        return (new self)->schemaFor(self::strip($example));
    }

    /**
     * Merged component schemas, keyed by component name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function components(): array
    {
        $out = [];
        foreach ($this->components as $name => $component) {
            $required = [];
            foreach ($component['keyCounts'] as $key => $count) {
                // Required: present in every observed shape, and not a nested resource (relations are optional).
                if ($count === $component['seen'] && ! self::isReferenceSchema($component['properties'][$key])) {
                    $required[] = $key;
                }
            }

            $schema = ['type' => 'object'];
            if ($required !== []) {
                $schema['required'] = $required;
            }
            $schema['properties'] = $component['properties'];
            $out[$name] = $schema;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $example
     * @return array{'$ref': string}
     */
    private function reference(string $class, array $example): array
    {
        $name = $this->nameFor($class);
        $this->components[$name] ??= ['properties' => [], 'seen' => 0, 'keyCounts' => []];
        $this->components[$name]['seen']++;

        unset($example[self::MARKER]);
        foreach ($example as $key => $value) {
            $key = (string) $key;
            $schema = $this->schemaFor($value);
            $enum = $this->enumValues !== null && ! isset($schema['$ref']) ? ($this->enumValues)($class, $key) : null;
            if ($enum !== null && $enum !== []) {
                $schema = array_slice($schema, 0, 1, true) + ['enum' => $enum] + $schema;
            }
            $this->components[$name]['keyCounts'][$key] = ($this->components[$name]['keyCounts'][$key] ?? 0) + 1;
            $existing = $this->components[$name]['properties'][$key] ?? null;
            $this->components[$name]['properties'][$key] = $existing === null ? $schema : self::mergeSchemas($existing, $schema);
        }

        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * @param  list<mixed>  $items
     * @return array<string, mixed>|\stdClass
     */
    private function itemsSchema(array $items): array|\stdClass
    {
        $schema = null;
        foreach ($items as $item) {
            $itemSchema = $this->schemaFor($item);
            $schema = $schema === null ? $itemSchema : self::mergeSchemas($schema, $itemSchema);
        }

        return $schema ?? new \stdClass;
    }

    /**
     * Component name: resource basename without "Resource" (ProductResource → Product), model basename.
     * A clash with another class gets the parent namespace segment as prefix (V1Product), then a number.
     */
    private function nameFor(string $class): string
    {
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }

        $base = class_basename($class);
        $isModel = is_subclass_of($class, Model::class);
        if (! $isModel && strlen($base) > strlen('Resource') && str_ends_with($base, 'Resource')) {
            $base = substr($base, 0, -strlen('Resource'));
        }

        $segments = explode('\\', $class);
        $parent = count($segments) > 1 ? Str::studly($segments[count($segments) - 2]) : '';
        $candidates = [$base, $isModel ? $base . 'Model' : $parent . $base];
        $name = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $this->isFree($candidate, $class)) {
                $name = $candidate;

                break;
            }
        }
        for ($n = 2; $name === null; $n++) {
            if ($this->isFree($base . $n, $class)) {
                $name = $base . $n;
            }
        }

        $this->owners[$name] = $class;

        return $this->names[$class] = $name;
    }

    private function isFree(string $name, string $class): bool
    {
        if (in_array($name, $this->reserved, true)) {
            return false;
        }

        return ! isset($this->owners[$name]) || $this->owners[$name] === $class;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scalarSchema(mixed $value): array
    {
        if ($value === null) {
            return ['nullable' => true];
        }
        if (is_bool($value)) {
            return ['type' => 'boolean', 'example' => $value];
        }
        if (is_int($value)) {
            return ['type' => 'integer', 'example' => $value];
        }
        if (is_float($value)) {
            return ['type' => 'number', 'example' => $value];
        }

        $value = (string) $value;
        $schema = ['type' => 'string'];
        $format = self::stringFormat($value);
        if ($format !== null) {
            $schema['format'] = $format;
        }
        $schema['example'] = $value;

        return $schema;
    }

    private static function stringFormat(string $value): ?string
    {
        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) => 'date-time',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) => 'date',
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) => 'uuid',
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false => 'email',
            (bool) preg_match('#^https?://#i', $value) => 'uri',
            default => null,
        };
    }

    /**
     * Combine two observations of the same value: union object properties, widen to nullable when one
     * side is null, keep the first type otherwise.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function mergeSchemas(array $a, array $b): array
    {
        if ($a === $b) {
            return $a;
        }
        if (! isset($a['type']) && ! isset($a['$ref']) && ($a['nullable'] ?? false)) {
            return $b + ['nullable' => true];
        }
        if (! isset($b['type']) && ! isset($b['$ref']) && ($b['nullable'] ?? false)) {
            return isset($a['$ref']) ? $a : $a + ['nullable' => true];
        }
        if (($a['type'] ?? null) === 'object' && ($b['type'] ?? null) === 'object') {
            $properties = $a['properties'] ?? [];
            foreach ($b['properties'] ?? [] as $key => $schema) {
                $properties[$key] = isset($properties[$key]) ? self::mergeSchemas($properties[$key], $schema) : $schema;
            }
            $a['properties'] = $properties;

            return $a;
        }
        if (($a['type'] ?? null) === 'array' && ($b['type'] ?? null) === 'array' && is_array($a['items'] ?? null) && is_array($b['items'] ?? null)) {
            $a['items'] = self::mergeSchemas($a['items'], $b['items']);

            return $a;
        }

        return $a;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function isReferenceSchema(array $schema): bool
    {
        return isset($schema['$ref']) || (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null) && isset($schema['items']['$ref']));
    }
}
