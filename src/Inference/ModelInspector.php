<?php

namespace G4T\Swagger\Inference;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use UnitEnum;

/**
 * Reads Eloquent model metadata (casts, dates, hidden, fillable, relations) to type example values.
 */
final class ModelInspector
{
    /** @var array<string, array{casts: array<string, mixed>, dates: list<string>, hidden: list<string>, fillable: list<string>, key: string, timestamps: list<string>}|null> */
    private array $info = [];

    /** @var array<string, array{class: string, many: bool}|null> */
    private array $relations = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $examples = [];

    /**
     * @param  (Closure(string, Throwable): void)|null  $onError
     */
    public function __construct(private ?Closure $onError = null)
    {
    }

    public function isModel(?string $class): bool
    {
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        return ! (new ReflectionClass($class))->isAbstract();
    }

    /**
     * @return array{casts: array<string, mixed>, dates: list<string>, hidden: list<string>, fillable: list<string>, key: string, timestamps: list<string>}|null
     */
    public function info(string $class): ?array
    {
        if (array_key_exists($class, $this->info)) {
            return $this->info[$class];
        }

        $info = null;
        if ($this->isModel($class)) {
            try {
                /** @var Model $model */
                $model = new $class();
                $timestamps = $model->usesTimestamps()
                    ? array_values(array_filter([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()]))
                    : [];
                $info = [
                    'casts' => $model->getCasts(),
                    'dates' => array_values($model->getDates()),
                    'hidden' => array_values($model->getHidden()),
                    'fillable' => array_values($model->getFillable()),
                    'key' => $model->getKeyName(),
                    'timestamps' => $timestamps,
                ];
            } catch (Throwable $e) {
                $this->report('could not inspect model ' . $class, $e);
            }
        }

        return $this->info[$class] = $info;
    }

    /**
     * Example for a model attribute from its cast / date definition, or {@see Unresolved}.
     */
    public function attributeExample(string $class, string $attribute): mixed
    {
        $info = $this->info($class);
        if ($info === null) {
            return Unresolved::instance();
        }

        if (array_key_exists($attribute, $info['casts'])) {
            $example = $this->castExample($info['casts'][$attribute], $attribute);
            if (! $example instanceof Unresolved) {
                return $example;
            }
        }

        if ($attribute === $info['key']) {
            return 1;
        }

        if (in_array($attribute, $info['dates'], true) || in_array($attribute, $info['timestamps'], true)) {
            return ExampleGuesser::DATETIME;
        }

        return Unresolved::instance();
    }

    /**
     * Example for an Eloquent cast definition (e.g. "integer", "decimal:2", an enum or castable class).
     */
    public function castExample(mixed $cast, string $attribute): mixed
    {
        if (! is_string($cast) || $cast === '') {
            return Unresolved::instance();
        }

        [$base, $argument] = array_pad(explode(':', $cast, 2), 2, null);

        try {
            if (enum_exists($base)) {
                return $this->enumExample($base);
            }
            if (class_exists($base)) {
                if (is_a($base, AsEnumCollection::class, true) || is_a($base, AsEnumArrayObject::class, true)) {
                    $enum = $argument !== null ? explode(',', $argument)[0] : null;

                    return $enum !== null && enum_exists($enum) ? [$this->enumExample($enum)] : ['string'];
                }
                foreach ([AsArrayObject::class, AsCollection::class, AsEncryptedArrayObject::class, AsEncryptedCollection::class] as $arrayCast) {
                    if (is_a($base, $arrayCast, true)) {
                        return ExampleGuesser::collection($attribute);
                    }
                }

                return Unresolved::instance();
            }
        } catch (Throwable $e) {
            $this->report('could not inspect cast ' . $cast, $e);

            return Unresolved::instance();
        }

        $type = strtolower($base);
        if ($type === 'encrypted') {
            $type = strtolower((string) $argument);
        }

        return match ($type) {
            'int', 'integer', 'timestamp' => 1,
            'real', 'float', 'double', 'decimal' => 10.5,
            'bool', 'boolean' => true,
            'array', 'json', 'object', 'collection' => ExampleGuesser::collection($attribute),
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime' => ExampleGuesser::DATETIME,
            default => Unresolved::instance(),
        };
    }

    /**
     * Allowed values of an attribute cast to an enum (backed value or case name), else null.
     *
     * @return list<int|string>|null
     */
    public function enumValues(string $class, string $attribute): ?array
    {
        $cast = $this->info($class)['casts'][$attribute] ?? null;
        if (! is_string($cast)) {
            return null;
        }
        $cast = explode(':', $cast, 2)[0];
        if (! enum_exists($cast)) {
            return null;
        }

        return array_map(fn (UnitEnum $case) => $case instanceof BackedEnum ? $case->value : $case->name, $cast::cases());
    }

    private function enumExample(string $enum): mixed
    {
        /** @var list<UnitEnum> $cases */
        $cases = $enum::cases();
        if ($cases === []) {
            return 'string';
        }

        return $cases[0] instanceof BackedEnum ? $cases[0]->value : $cases[0]->name;
    }

    /**
     * Row-shaped example from fillable + key + timestamps, typed by casts, without hidden attributes.
     *
     * @return array<string, mixed>|null
     */
    public function modelExample(string $class): ?array
    {
        if (array_key_exists($class, $this->examples)) {
            return $this->examples[$class];
        }

        $info = $this->info($class);
        if ($info === null) {
            return $this->examples[$class] = null;
        }

        $attributes = array_unique(array_merge([$info['key']], $info['fillable'], $info['timestamps']));
        $out = [];
        foreach ($attributes as $attribute) {
            if (in_array($attribute, $info['hidden'], true)) {
                continue;
            }
            $value = $this->attributeExample($class, $attribute);
            $out[$attribute] = $value instanceof Unresolved ? ExampleGuesser::property($attribute) : $value;
        }
        if ($out !== []) {
            // Lets ResponseSchema turn this object into a #/components/schemas reference.
            $out[ResponseSchema::MARKER] = $class;
        }

        return $this->examples[$class] = $out;
    }

    /**
     * Related model of a relation method, only when the method declares a Relation return type
     * (methods without one are never called).
     *
     * @return array{class: string, many: bool}|null
     */
    public function relation(string $class, string $relation): ?array
    {
        $key = $class . '::' . $relation;
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        $result = null;
        try {
            if ($this->isModel($class) && method_exists($class, $relation)) {
                $method = new ReflectionMethod($class, $relation);
                $type = $method->getReturnType();
                if ($method->isPublic() && ! $method->isStatic() && $method->getNumberOfRequiredParameters() === 0
                    && $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    && is_a($type->getName(), Relation::class, true)) {
                    $instance = (new $class())->{$relation}();
                    if ($instance instanceof Relation) {
                        $single = $instance instanceof BelongsTo || $instance instanceof HasOne
                            || $instance instanceof MorphOne || $instance instanceof HasOneThrough;
                        $result = ['class' => get_class($instance->getRelated()), 'many' => ! $single];
                    }
                }
            }
        } catch (Throwable $e) {
            $this->report('could not inspect relation ' . $key, $e);
        }

        return $this->relations[$key] = $result;
    }

    private function report(string $message, Throwable $e): void
    {
        if ($this->onError !== null) {
            ($this->onError)($message, $e);
        }
    }
}
