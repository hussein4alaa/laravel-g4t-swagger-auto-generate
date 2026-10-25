<?php

namespace G4T\Swagger\Extensions;

use DateTimeInterface;
use G4T\Swagger\Inference\ExampleGuesser;
use G4T\Swagger\Inference\PhpSource;
use G4T\Swagger\Inference\ResponseSchema;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;

/**
 * Schema and example of a class from its public typed properties (promoted constructor parameters
 * included): DTOs, Spatie Data objects, value objects. Nothing is instantiated.
 *
 * `array` / collection properties are typed from a `@var list<X>`, `array<X>` or `X[]` doc comment.
 */
final class ClassShape
{
    private const MAX_DEPTH = 4;

    /**
     * @param  (callable(string): string)|null  $key  maps a property name to its JSON key (e.g. snake case)
     */
    public function __construct(private $key = null) {}

    /**
     * @return list<array{name: string, key: string, type: ?string, nullable: bool, optional: bool, item: ?string, list: bool}>
     */
    public function properties(string $class): array
    {
        try {
            $ref = new ReflectionClass($class);
        } catch (Throwable) {
            return [];
        }

        $defaults = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $parameter) {
            $defaults[$parameter->getName()] = $parameter->isDefaultValueAvailable();
        }

        $out = [];
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $type = $property->getType();
            $named = $type instanceof ReflectionNamedType ? $type : null;
            if ($type instanceof ReflectionUnionType) {
                // int|string, Foo|null: the first non-null member is enough for a schema.
                foreach ($type->getTypes() as $member) {
                    if ($member instanceof ReflectionNamedType && $member->getName() !== 'null') {
                        $named = $member;
                        break;
                    }
                }
            }
            $typeName = $named?->getName();
            $item = null;
            $list = in_array($typeName, ['array', 'iterable'], true) || ($typeName !== null && ! $named->isBuiltin() && class_exists($typeName) && is_subclass_of($typeName, \Traversable::class));
            if ($list) {
                $item = self::itemType($property, $ref);
            }
            $out[] = [
                'name' => $property->getName(),
                'key' => $this->key !== null ? (string) ($this->key)($property->getName()) : $property->getName(),
                'type' => $typeName,
                'nullable' => $type === null || $type->allowsNull(),
                'optional' => $property->hasDefaultValue() || ($defaults[$property->getName()] ?? false),
                'item' => $item,
                'list' => $list,
            ];
        }

        return $out;
    }

    /**
     * Example value; nested classes carry the component marker, so each becomes a `$ref` schema.
     *
     * @return array<string, mixed>
     */
    public function example(string $class, array $stack = []): array
    {
        $out = [ResponseSchema::MARKER => $class];
        foreach ($this->properties($class) as $p) {
            $out[$p['key']] = $p['list']
                ? ($p['item'] !== null ? [$this->value($p['item'], $p['name'], $stack + [$class => true])] : [])
                : $this->value($p['type'], $p['name'], $stack + [$class => true]);
        }

        return $out;
    }

    /**
     * Inline OpenAPI object schema (request bodies); nested classes are inlined too.
     *
     * @return array<string, mixed>
     */
    public function schema(string $class, array $stack = []): array
    {
        $properties = [];
        $required = [];
        foreach ($this->properties($class) as $p) {
            $schema = $p['list']
                ? ['type' => 'array', 'items' => $p['item'] !== null ? $this->typeSchema($p['item'], $stack + [$class => true]) : new \stdClass]
                : $this->typeSchema($p['type'], $stack + [$class => true]);
            if ($p['nullable'] && $p['type'] !== null) {
                $schema['nullable'] = true;
            }
            $properties[$p['key']] = $schema;
            if (! $p['nullable'] && ! $p['optional']) {
                $required[] = $p['key'];
            }
        }

        return array_filter(['type' => 'object', 'required' => $required, 'properties' => $properties], fn ($v) => $v !== []);
    }

    private function value(?string $type, string $name, array $stack): mixed
    {
        $guess = ExampleGuesser::property($name);

        return match (true) {
            $type === null, $type === 'mixed' => $guess,
            $type === 'int' => 1,
            $type === 'float' => 10.5,
            $type === 'bool' => true,
            $type === 'string' => is_string($guess) && $guess !== 'string' ? $guess : 'Example',
            in_array($type, ['array', 'iterable', 'object'], true) => [],
            is_a($type, DateTimeInterface::class, true) => ExampleGuesser::DATETIME,
            interface_exists('BackedEnum') && is_subclass_of($type, 'BackedEnum') => $type::cases()[0]->value ?? null,
            interface_exists('UnitEnum') && is_subclass_of($type, 'UnitEnum') => $type::cases()[0]->name ?? null,
            class_exists($type) && ! isset($stack[$type]) && count($stack) < self::MAX_DEPTH => $this->example($type, $stack),
            default => $guess,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function typeSchema(?string $type, array $stack): array
    {
        return match (true) {
            $type === null, $type === 'mixed' => [],
            $type === 'int' => ['type' => 'integer'],
            $type === 'float' => ['type' => 'number'],
            $type === 'bool' => ['type' => 'boolean'],
            $type === 'string' => ['type' => 'string'],
            in_array($type, ['array', 'iterable'], true) => ['type' => 'array', 'items' => new \stdClass],
            $type === 'object' => ['type' => 'object'],
            is_a($type, DateTimeInterface::class, true) => ['type' => 'string', 'format' => 'date-time'],
            interface_exists('BackedEnum') && is_subclass_of($type, 'BackedEnum') => ['type' => is_int($type::cases()[0]->value ?? '') ? 'integer' : 'string', 'enum' => array_map(fn ($c) => $c->value, $type::cases())],
            interface_exists('UnitEnum') && is_subclass_of($type, 'UnitEnum') => ['type' => 'string', 'enum' => array_map(fn ($c) => $c->name, $type::cases())],
            class_exists($type) && ! isset($stack[$type]) && count($stack) < self::MAX_DEPTH => $this->schema($type, $stack),
            default => ['type' => 'object'],
        };
    }

    /**
     * X from `@var list<X>`, `array<int, X>`, `Collection<X>`, `X[]` on the property or the promoted parameter.
     */
    private static function itemType(ReflectionProperty $property, ReflectionClass $class): ?string
    {
        $doc = (string) $property->getDocComment();
        if ($doc === '' && $class->getConstructor() !== null) {
            // Promoted parameters document their type in the constructor's doc block: @param list<X> $name
            $ctorDoc = (string) $class->getConstructor()->getDocComment();
            if (preg_match('/@param\s+(\S+)\s+\$'.preg_quote($property->getName(), '/').'\b/', $ctorDoc, $pm)) {
                $doc = '@var '.$pm[1];
            }
        }
        if (! preg_match('/@var\s+\??(?:[\w\\\\]+<(?:[^,>]+,\s*)?([\w\\\\]+)>|([\w\\\\]+)\[\])/', $doc, $m)) {
            return null;
        }
        $name = $m[1] !== '' ? $m[1] : $m[2];
        if (in_array(strtolower($name), ['int', 'string', 'bool', 'float', 'mixed'], true)) {
            return strtolower($name);
        }

        return (new PhpSource)->resolveClass($name, (string) $class->getFileName()) ?? (class_exists($name) ? $name : null);
    }
}
