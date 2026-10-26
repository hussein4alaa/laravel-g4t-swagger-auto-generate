<?php

namespace G4T\Swagger\Support;

use G4T\Swagger\Extensions\ClassShape;
use G4T\Swagger\Inference\ResponseSchema;
use G4T\Swagger\ResponseExampleExtractor;
use Throwable;

/**
 * Webhooks the API sends (swagger.webhooks, from the config file or the dashboard), documented as `x-webhooks`:
 * OpenAPI 3.0.3 has no `webhooks`, so they keep the shape of 3.1's (name => {post: operation}).
 *
 * A payload is a JSON `example`, or a `class`: a JsonResource, an Eloquent model or a DTO (public typed
 * properties, {@see ClassShape}). An `example` given next to a `class` replaces the generated one.
 *
 * Like operations, a webhook can be hidden from the docs (`hidden` in the config, or the dashboard's eye);
 * partner links that pick it still show it.
 */
final class Webhooks
{
    public const METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * Every valid webhook, in order; the config takes a list or a name => webhook map.
     *
     * @return list<array{name: string, summary: string, description: string, method: string, headers: list<array{name: string, description: string, example: string}>, example: mixed, class: ?string, hidden_in_code: bool}>
     */
    public static function all(): array
    {
        $out = [];
        $seen = [];
        foreach ((array) config('swagger.webhooks', []) as $key => $webhook) {
            if (! is_array($webhook)) {
                continue;
            }
            $name = trim((string) ($webhook['name'] ?? (is_string($key) ? $key : '')));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $method = strtoupper((string) ($webhook['method'] ?? 'POST'));
            $out[] = [
                'name' => $name,
                'summary' => (string) ($webhook['summary'] ?? ''),
                'description' => (string) ($webhook['description'] ?? ''),
                'method' => in_array($method, self::METHODS, true) ? $method : 'POST',
                'headers' => self::headers($webhook['headers'] ?? []),
                'example' => $webhook['example'] ?? null,
                'class' => is_string($webhook['class'] ?? null) && $webhook['class'] !== '' ? ltrim($webhook['class'], '\\') : null,
                'hidden_in_code' => filter_var($webhook['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::all(), 'name');
    }

    /**
     * Webhooks hidden from the docs: in the config or with the dashboard's eye.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        $hidden = array_flip(Settings::layout()['hidden_webhooks']);

        return array_values(array_column(array_filter(self::all(), fn (array $webhook) => $webhook['hidden_in_code'] || isset($hidden[$webhook['name']])), 'name'));
    }

    /**
     * The `x-webhooks` object: name => {method: {summary, description, parameters, requestBody, responses}}.
     * Hidden webhooks are left out unless $includeHidden (partner links that pick them).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function document(bool $includeHidden = false): array
    {
        $out = [];
        $extractor = null;
        $hidden = $includeHidden ? [] : array_flip(self::hidden());
        foreach (self::all() as $webhook) {
            if (isset($hidden[$webhook['name']])) {
                continue;
            }
            [$example, $schema] = self::payload($webhook, $extractor);
            $content = array_filter(['schema' => $schema, 'example' => $example], fn ($value) => $value !== null);
            $parameters = array_map(fn (array $header) => array_filter([
                'name' => $header['name'],
                'in' => 'header',
                'required' => true,
                'description' => $header['description'],
                'schema' => ['type' => 'string'],
                'example' => $header['example'],
            ], fn ($value) => $value !== ''), $webhook['headers']);

            $out[$webhook['name']] = [strtolower($webhook['method']) => array_filter([
                'summary' => $webhook['summary'],
                'description' => $webhook['description'],
                'parameters' => $parameters,
                'requestBody' => $content === [] ? null : ['required' => true, 'content' => ['application/json' => $content]],
                'responses' => ['200' => ['description' => 'Any 2xx status tells the API the webhook was received.']],
            ], fn ($value) => $value !== '' && $value !== [] && $value !== null)];
        }

        return $out;
    }

    /**
     * ["X-Signature" => "description"] or [["name" => …, "description" => …, "example" => …]].
     *
     * @return list<array{name: string, description: string, example: string}>
     */
    private static function headers(mixed $headers): array
    {
        $out = [];
        foreach (is_array($headers) ? $headers : [] as $key => $header) {
            if (is_string($key) && (is_string($header) || $header === null)) {
                $header = ['name' => $key, 'description' => (string) $header];
            }
            if (! is_array($header) || ! is_string($header['name'] ?? null) || trim($header['name']) === '') {
                continue;
            }
            $out[] = ['name' => trim($header['name']), 'description' => (string) ($header['description'] ?? ''), 'example' => (string) ($header['example'] ?? '')];
        }

        return $out;
    }

    /**
     * Example and inline schema of a webhook's payload.
     *
     * @param  array{name: string, example: mixed, class: ?string}  $webhook
     * @return array{0: mixed, 1: array<string, mixed>|null}
     */
    private static function payload(array $webhook, ?ResponseExampleExtractor &$extractor): array
    {
        $example = $webhook['example'];
        $schema = null;
        $class = $webhook['class'];
        if ($class !== null && class_exists($class)) {
            try {
                $extractor ??= new ResponseExampleExtractor;
                $generated = $extractor->classExample($class);
                if ($generated === null) {
                    $shape = new ClassShape;
                    $generated = ResponseSchema::strip($shape->example($class));
                    $schema = $example === null ? $shape->schema($class) : null;
                }
                $example ??= $generated;
            } catch (Throwable $e) {
                Debug::log("could not read {$class}, the payload of the {$webhook['name']} webhook", $e);
            }
        } elseif ($class !== null) {
            Debug::log("the payload class {$class} of the {$webhook['name']} webhook does not exist");
        }
        if ($example !== null && $schema === null) {
            $schema = ResponseSchema::plain($example);
        }

        return [$example, $schema];
    }
}
