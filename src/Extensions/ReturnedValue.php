<?php

namespace G4T\Swagger\Extensions;

use G4T\Swagger\Inference\CodeScanner;
use G4T\Swagger\Inference\PhpSource;

/**
 * An expression a controller (or a repository / helper it calls) returns, as source code. Nothing is
 * executed: hooks recognise the shape of the code.
 */
final class ReturnedValue
{
    /**
     * @param  string  $source  the returned expression, e.g. `UserData::from($user)`
     * @param  string  $class  class of the method that returns it
     * @param  string  $method  that method
     * @param  string  $file  file declaring the method (to resolve imported class names)
     * @param  \Closure(string): ?string  $resolver  resolves a class name as written in that file
     */
    public function __construct(
        public string $source,
        public string $class,
        public string $method,
        public string $file,
        private \Closure $resolver,
    ) {}

    /**
     * Fully qualified class of a name as written in the method's file (imports, aliases, self / static).
     */
    public function resolveClass(string $name): ?string
    {
        return ($this->resolver)($name);
    }

    /**
     * `Foo::bar(...)` as the whole expression: the resolved class, the method and the arguments' source.
     *
     * @return array{class: string, method: string, arguments: list<string>}|null
     */
    public function staticCall(): ?array
    {
        $masked = PhpSource::mask($this->source);
        if (! preg_match('/^\s*(\\\\?[A-Za-z_][\w\\\\]*)\s*::\s*(\w+)\s*\(/', $masked, $m)) {
            return null;
        }
        $class = $this->resolveClass($m[1]);
        $open = strlen($m[0]) - 1;
        if ($class === null || CodeScanner::matchClose($masked, $open) !== strlen(rtrim($masked)) - 1) {
            return null;
        }

        return ['class' => $class, 'method' => $m[2], 'arguments' => $this->arguments($masked, $open)];
    }

    /**
     * `new Foo(...)` as the whole expression.
     *
     * @return array{class: string, arguments: list<string>}|null
     */
    public function newObject(): ?array
    {
        $masked = PhpSource::mask($this->source);
        if (! preg_match('/^\s*new\s+(\\\\?[A-Za-z_][\w\\\\]*)\s*\(/', $masked, $m)) {
            return null;
        }
        $class = $this->resolveClass($m[1]);
        $open = strlen($m[0]) - 1;
        if ($class === null || CodeScanner::matchClose($masked, $open) !== strlen(rtrim($masked)) - 1) {
            return null;
        }

        return ['class' => $class, 'arguments' => $this->arguments($masked, $open)];
    }

    /**
     * True when the expression is a static call on $class (or a subclass), e.g. `UserData::from(...)`.
     */
    public function isStaticCallOn(string $class, ?string $method = null): bool
    {
        $call = $this->staticCall();

        return $call !== null && is_a($call['class'], $class, true) && ($method === null || $call['method'] === $method);
    }

    /**
     * @return list<string>
     */
    private function arguments(string $masked, int $open): array
    {
        return array_map(fn (array $argument) => $argument['orig'], CodeScanner::arguments($this->source, $masked, $open));
    }
}
