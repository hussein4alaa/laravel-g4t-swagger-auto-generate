<?php

namespace G4T\Swagger\Extensions;

use G4T\Swagger\Inference\ResponseSchema;

/**
 * What an extension's response() hook returns: the example of a returned value.
 */
final class Example
{
    private function __construct(public mixed $value) {}

    /**
     * A literal example. With a component name (a class name works best), an object example becomes a
     * reusable schema under #/components/schemas and the operation references it.
     */
    public static function of(mixed $value, ?string $component = null): self
    {
        if ($component !== null && is_array($value) && ! array_is_list($value)) {
            $value = [ResponseSchema::MARKER => $component] + $value;
        }

        return new self($value);
    }

    /**
     * An example built from the public typed properties of a class; it and every nested class become
     * components.
     *
     * @param  (callable(string): string)|null  $key  property name → JSON key, e.g. fn ($n) => Str::snake($n)
     */
    public static function fromClass(string $class, ?callable $key = null): self
    {
        return new self((new ClassShape($key))->example($class));
    }

    /**
     * A list of the class's example (for collections).
     *
     * @param  (callable(string): string)|null  $key
     */
    public static function listOf(string $class, ?callable $key = null): self
    {
        return new self([(new ClassShape($key))->example($class)]);
    }

    /**
     * This example wrapped in an envelope, e.g. ['data' => …] or a JSON:API document.
     *
     * @param  callable(mixed): mixed  $wrap
     */
    public function wrap(callable $wrap): self
    {
        return new self($wrap($this->value));
    }
}
