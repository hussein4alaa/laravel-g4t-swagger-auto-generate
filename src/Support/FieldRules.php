<?php

namespace G4T\Swagger\Support;

use BackedEnum;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\ImageFile;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\RequiredIf;
use ReflectionProperty;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Normalised validation rules of a single field.
 *
 * Rules are parsed into exact rule names (`required`, `required_if`, `max`, ...) so that
 * `required_if` is never mistaken for `required`, a field named `profile_id` is never a file, etc.
 */
final class FieldRules
{
    private const FILE_RULES = ['file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'];

    /**
     * @param  array<string, list<string>>  $rules  rule name => parameters
     * @param  list<int|string>|null  $enum  allowed values coming from rule objects
     */
    private function __construct(
        private array $rules,
        private ?array $enum,
    ) {}

    /**
     * Parse any Laravel rule definition: pipe string, array of strings / rule objects, single object or closure.
     */
    public static function parse(mixed $raw): self
    {
        $rules = [];
        $enum = null;

        if (is_string($raw)) {
            foreach (self::splitPipeString($raw) as $part) {
                self::addStringRule($rules, $part);
            }

            return new self($rules, null);
        }

        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            if (is_string($item)) {
                $parts = preg_match('/^\s*(not_)?regex:/i', $item) ? [$item] : self::splitPipeString($item);
                foreach ($parts as $part) {
                    self::addStringRule($rules, $part);
                }

                continue;
            }

            if (! is_object($item)) {
                continue;
            }

            try {
                if ($item instanceof Enum) {
                    $enum = self::enumValues($item);
                    $rules['enum'] = [];
                } elseif ($item instanceof RequiredIf) {
                    $rules['conditional_required'] = [];
                } elseif ($item instanceof Password) {
                    $rules['string'] = [];
                    $min = self::readProperty($item, 'min');
                    if (is_int($min) && $min > 0) {
                        $rules['min'] = [(string) $min];
                    }
                } elseif ($item instanceof File) {
                    $rules[$item instanceof ImageFile ? 'image' : 'file'] = [];
                    $extensions = self::readProperty($item, 'allowedExtensions');
                    if (is_array($extensions) && $extensions !== []) {
                        $rules['extensions'] = array_values(array_map('strval', $extensions));
                    }
                    $maxSize = self::readProperty($item, 'maximumFileSize');
                    if (is_numeric($maxSize)) {
                        $rules['max'] = [(string) $maxSize];
                    }
                } elseif ($item instanceof Email) {
                    $rules['email'] = [];
                } elseif ($item instanceof Stringable || method_exists($item, '__toString')) {
                    $string = (string) $item;
                    if ($string !== '') {
                        foreach (self::splitPipeString($string) as $part) {
                            self::addStringRule($rules, $part);
                        }
                    }
                }
            } catch (Throwable $e) {
                Debug::log('could not read rule object '.$item::class, $e);
            }
            // Closures and custom invokable rules carry no schema information.
        }

        return new self($rules, $enum);
    }

    public function has(string $rule): bool
    {
        return array_key_exists($rule, $this->rules);
    }

    /**
     * @return list<string>|null
     */
    public function params(string $rule): ?array
    {
        return $this->rules[$rule] ?? null;
    }

    public function isRequired(): bool
    {
        return $this->has('required') && ! $this->has('sometimes');
    }

    public function isNullable(): bool
    {
        return $this->has('nullable');
    }

    public function isFile(): bool
    {
        foreach (self::FILE_RULES as $rule) {
            if ($this->has($rule)) {
                return true;
            }
        }

        return false;
    }

    public function isArray(): bool
    {
        return $this->has('array') || $this->has('list');
    }

    /**
     * A copy without the given rules (used for `*_confirmation` fields).
     *
     * @param  list<string>  $names
     */
    public function without(array $names): self
    {
        return new self(array_diff_key($this->rules, array_flip($names)), $this->enum);
    }

    /**
     * OpenAPI 3.0 schema for a scalar field (arrays/objects are assembled by SchemaBuilder).
     *
     * @return array<string, mixed>
     */
    public function toSchema(string $field = ''): array
    {
        $schema = ['type' => $this->openApiType($field)];
        $description = [];

        if ($schema['type'] === 'string') {
            $format = $this->stringFormat();
            if ($format !== null) {
                $schema['format'] = $format;
            }
        }

        $enum = $this->enumValuesFor($schema['type']);
        if ($enum !== null) {
            $schema['enum'] = $enum;
        }

        $this->applyBounds($schema, $description);

        if ($this->isNullable()) {
            $schema['nullable'] = true;
        }

        $regex = $this->params('regex');
        if ($regex !== null && ! isset($schema['format'])) {
            $pattern = $this->regexToPattern(implode(',', $regex));
            if ($pattern !== null) {
                $schema['pattern'] = $pattern;
            }
        }

        foreach (['mimes' => 'Allowed types', 'extensions' => 'Allowed extensions', 'mimetypes' => 'Allowed MIME types'] as $rule => $label) {
            if (! empty($this->params($rule))) {
                $description[] = $label.': '.implode(', ', $this->params($rule)).'.';
            }
        }

        if ($this->has('exists')) {
            [$table, $column] = $this->tableAndColumn('exists', $field);
            $description[] = "Must exist in {$table}.{$column}.";
        }
        if ($this->has('unique')) {
            [$table, $column] = $this->tableAndColumn('unique', $field);
            $description[] = "Must be unique in {$table}.{$column}.";
        }

        $description = array_merge($description, $this->conditionalDescriptions());

        if ($description !== []) {
            $schema['description'] = implode(' ', $description);
        }

        return $schema;
    }

    private function openApiType(string $field): string
    {
        if ($this->isFile()) {
            return 'string';
        }
        if ($this->has('integer') || $this->has('digits') || $this->has('digits_between')) {
            return 'integer';
        }
        if ($this->has('numeric') || $this->has('decimal')) {
            return 'number';
        }
        if ($this->has('boolean') || $this->has('bool') || $this->has('accepted') || $this->has('declined')) {
            return 'boolean';
        }
        if ($this->isArray()) {
            return 'array';
        }
        if ($this->has('string') || $this->has('email') || $this->has('uuid') || $this->has('date')) {
            return 'string';
        }
        if ($this->has('exists')) {
            [, $column] = $this->tableAndColumn('exists', $field);
            if ($column === 'id' || str_ends_with($column, '_id')) {
                return 'integer';
            }
        }

        return 'string';
    }

    private function stringFormat(): ?string
    {
        if ($this->isFile()) {
            return 'binary';
        }

        return match (true) {
            $this->has('email') => 'email',
            $this->has('uuid') => 'uuid',
            $this->has('url'), $this->has('active_url') => 'uri',
            $this->has('ipv4') => 'ipv4',
            $this->has('ipv6') => 'ipv6',
            $this->has('date_format') => $this->dateFormatIsDateTime() ? 'date-time' : 'date',
            $this->has('date') => 'date',
            default => null,
        };
    }

    private function dateFormatIsDateTime(): bool
    {
        $format = implode(',', $this->params('date_format') ?? []);

        return (bool) preg_match('/[HhGgisuvU]/', $format);
    }

    /**
     * @return list<int|float|string|bool>|null
     */
    private function enumValuesFor(string $type): ?array
    {
        $values = $this->enum;
        if ($values === null && $this->has('in')) {
            $values = $this->params('in');
        }
        if ($values === null || $values === []) {
            return null;
        }

        return array_values(array_map(static fn ($value) => match ($type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'number' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => in_array($value, [true, 1, '1', 'true'], true),
            default => (string) $value,
        }, $values));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $description
     */
    private function applyBounds(array &$schema, array &$description): void
    {
        [$min, $max] = [$this->firstNumber('min'), $this->firstNumber('max')];

        $size = $this->firstNumber('size');
        if ($size !== null) {
            [$min, $max] = [$size, $size];
        }
        $between = $this->params('between');
        if ($between !== null && count($between) === 2 && is_numeric($between[0]) && is_numeric($between[1])) {
            [$min, $max] = [$between[0] + 0, $between[1] + 0];
        }

        if (($schema['format'] ?? null) === 'binary') {
            if ($max !== null) {
                $description[] = "Max size: {$max} KB.";
            }

            return;
        }

        if ($schema['type'] === 'integer' && $this->has('digits')) {
            $digits = (int) $this->firstNumber('digits');
            $schema['minimum'] = $digits > 1 ? 10 ** ($digits - 1) : 0;
            $schema['maximum'] = 10 ** $digits - 1;

            return;
        }

        [$minKey, $maxKey] = match ($schema['type']) {
            'integer', 'number' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            'boolean' => [null, null],
            default => ['minLength', 'maxLength'],
        };
        if ($minKey === null) {
            return;
        }

        $integerOnly = $minKey !== 'minimum' || $schema['type'] === 'integer';
        if ($min !== null) {
            $schema[$minKey] = $integerOnly ? (int) $min : $min;
        }
        if ($max !== null) {
            $schema[$maxKey] = $integerOnly ? (int) $max : $max;
        }
    }

    private function firstNumber(string $rule): int|float|null
    {
        $params = $this->params($rule);
        if ($params === null || ! isset($params[0]) || ! is_numeric($params[0])) {
            return null;
        }

        return $params[0] + 0;
    }

    /**
     * @return list<string>
     */
    private function conditionalDescriptions(): array
    {
        $lines = [];
        $templates = [
            'required_if' => 'Required when %s is %s.',
            'required_unless' => 'Required unless %s is %s.',
            'required_with' => 'Required when %s is present.',
            'required_with_all' => 'Required when all of %s are present.',
            'required_without' => 'Required when %s is not present.',
            'required_without_all' => 'Required when none of %s are present.',
        ];
        foreach ($templates as $rule => $template) {
            $params = $this->params($rule);
            if ($params === null || $params === []) {
                continue;
            }
            if (in_array($rule, ['required_if', 'required_unless'], true)) {
                $lines[] = sprintf($template, $params[0], implode(' or ', array_slice($params, 1)));
            } else {
                $lines[] = sprintf($template, implode(', ', $params));
            }
        }
        if ($this->has('conditional_required')) {
            $lines[] = 'Conditionally required.';
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function tableAndColumn(string $rule, string $field): array
    {
        $params = $this->params($rule) ?? [];
        $table = $params[0] ?? '';
        if (str_contains($table, '\\') && class_exists($table)) {
            try {
                $table = (new $table)->getTable();
            } catch (Throwable) {
            }
        }
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        $column = isset($params[1]) && $params[1] !== '' && strtoupper($params[1]) !== 'NULL'
            ? $params[1]
            : ($field !== '' ? $field : 'id');

        return [$table, $column];
    }

    private function regexToPattern(string $regex): ?string
    {
        if (preg_match('#^/(.*)/$#s', $regex, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function splitPipeString(string $rules): array
    {
        $parts = [];
        $buffer = '';
        foreach (explode('|', $rules) as $segment) {
            // A regex rule may itself contain "|": keep it intact until the pattern delimiter closes.
            if ($buffer !== '') {
                $buffer .= '|'.$segment;
                if (preg_match('#^\s*(not_)?regex:/.*/[a-z]*$#is', $buffer)) {
                    $parts[] = $buffer;
                    $buffer = '';
                }

                continue;
            }
            if (preg_match('#^\s*(not_)?regex:/#i', $segment) && ! preg_match('#^\s*(not_)?regex:/.*/[a-z]*$#is', $segment)) {
                $buffer = $segment;

                continue;
            }
            $parts[] = $segment;
        }
        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /**
     * @param  array<string, list<string>>  $rules
     */
    private static function addStringRule(array &$rules, string $rule): void
    {
        $rule = trim($rule);
        if ($rule === '') {
            return;
        }

        [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
        $name = strtolower(trim($name));

        if ($parameters === null || $parameters === '') {
            $rules[$name] = [];

            return;
        }

        $rules[$name] = in_array($name, ['regex', 'not_regex'], true)
            ? [$parameters]
            : array_values(array_map('trim', str_getcsv($parameters, ',', '"', '\\')));
    }

    /**
     * @return list<int|string>
     */
    private static function enumValues(Enum $rule): array
    {
        $class = self::readProperty($rule, 'type');
        if (! is_string($class) || ! enum_exists($class)) {
            return [];
        }

        $cases = $class::cases();
        $only = self::readProperty($rule, 'only');
        $except = self::readProperty($rule, 'except');
        if (is_array($only) && $only !== []) {
            $cases = array_filter($cases, static fn (UnitEnum $case) => in_array($case, $only, true));
        }
        if (is_array($except) && $except !== []) {
            $cases = array_filter($cases, static fn (UnitEnum $case) => ! in_array($case, $except, true));
        }

        return array_values(array_map(
            static fn (UnitEnum $case) => $case instanceof BackedEnum ? $case->value : $case->name,
            $cases,
        ));
    }

    private static function readProperty(object $object, string $property): mixed
    {
        if (! property_exists($object, $property)) {
            return null;
        }

        $reflection = new ReflectionProperty($object, $property);

        return $reflection->isInitialized($object) ? $reflection->getValue($object) : null;
    }
}
