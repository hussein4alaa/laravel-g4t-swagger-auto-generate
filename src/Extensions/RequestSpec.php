<?php

namespace G4T\Swagger\Extensions;

/**
 * What an extension's request() hook returns: the rules or the schema of a route's input.
 */
final class RequestSpec
{
    /**
     * @param  array<string, mixed>|null  $rules  Laravel validation rules
     * @param  array<string, mixed>|null  $schema  OpenAPI object schema of a JSON body
     */
    private function __construct(
        public ?array $rules,
        public ?array $schema,
        public bool $query,
    ) {}

    /**
     * Laravel validation rules, read exactly like a FormRequest (types, enums, files, nested fields).
     * They become the body of POST / PUT / PATCH routes and the query of the others.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function fromRules(array $rules): self
    {
        return new self($rules, null, false);
    }

    /**
     * An OpenAPI object schema for the JSON body, as is.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function fromSchema(array $schema): self
    {
        return new self(null, $schema, false);
    }

    /**
     * A JSON body typed from the public properties of a class (a DTO, a Data object…).
     *
     * @param  (callable(string): string)|null  $key  property name → JSON key, e.g. fn ($n) => Str::snake($n)
     */
    public static function fromClass(string $class, ?callable $key = null): self
    {
        return new self(null, (new ClassShape($key))->schema($class), false);
    }

    /**
     * The rules describe query parameters, also on POST / PUT / PATCH routes.
     */
    public function asQuery(): self
    {
        return new self($this->rules, $this->schema, true);
    }
}
