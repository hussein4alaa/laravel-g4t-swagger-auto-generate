<?php

namespace G4T\Swagger\Support;

use stdClass;

/**
 * Flows: requests the g4t UI runs one after another in one click (log in, create an order, pay it…), saved in the
 * dashboard layout and documented as `x-flows`. A step names an operation (operationId) with its path / query values,
 * headers and JSON body; `{{name}}` in them is filled from the environment or from what an earlier step extracted
 * ({var, from}: "body.data.token", "headers.x-request-id" or "status").
 *
 * A flow only reaches a document holding every operation it uses (hidden ones, other versions, partner links).
 * Like operations, a flow can be hidden from the docs (the dashboard's eye); partner links that pick it still show it.
 */
final class Flows
{
    public const MAX_FLOWS = 50;

    public const MAX_STEPS = 30;

    /** Same names the g4t UI fills in {{name}}. */
    public const VARIABLE = '/^[A-Za-z_][\w.-]*$/';

    /** Where an extracted value comes from. */
    public const SOURCE = '/^(status|headers\.[A-Za-z0-9_-]+|body(\.[^.\s]+)*)$/';

    /**
     * Flows with only well-formed values, as layout.flows keeps them.
     *
     * @return list<array{id: string, name: string, description: array{en: string, ar: string}, steps: list<array{operation: string, params: array<string, string>, headers: array<string, string>, body: string, extract: list<array{var: string, from: string}>}>}>
     */
    public static function clean(mixed $flows): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($flows) ? $flows : [] as $flow) {
            $id = is_array($flow) && is_string($flow['id'] ?? null) ? $flow['id'] : '';
            if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $steps = [];
            foreach (is_array($flow['steps'] ?? null) ? array_values($flow['steps']) : [] as $step) {
                if (is_array($step) && is_string($step['operation'] ?? null) && $step['operation'] !== '' && count($steps) < self::MAX_STEPS) {
                    $steps[] = [
                        'operation' => mb_substr($step['operation'], 0, 200),
                        'params' => self::pairs($step['params'] ?? null),
                        'headers' => self::pairs($step['headers'] ?? null),
                        'body' => is_string($step['body'] ?? null) ? mb_substr($step['body'], 0, 20000) : '',
                        'extract' => self::extracts($step['extract'] ?? null),
                    ];
                }
            }
            $description = [];
            foreach (Settings::LANGUAGES as $language) {
                $text = $flow['description'][$language] ?? null;
                $description[$language] = is_string($text) ? mb_substr(trim($text), 0, 2000) : '';
            }
            $out[] = [
                'id' => $id,
                'name' => is_string($flow['name'] ?? null) && trim($flow['name']) !== '' ? mb_substr(trim($flow['name']), 0, 100) : 'Flow',
                'description' => $description,
                'steps' => $steps,
            ];
            if (count($out) >= self::MAX_FLOWS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Saved flows, without the hidden ones unless $includeHidden.
     *
     * @return list<array<string, mixed>>
     */
    public static function saved(bool $includeHidden = false): array
    {
        $layout = Settings::layout();
        $hidden = $includeHidden ? [] : array_flip($layout['hidden_flows']);

        return array_values(array_filter($layout['flows'], fn (array $flow) => ! isset($hidden[$flow['id']])));
    }

    /**
     * Keeps the flows of a decoded document that a partner link shows: $ids (hidden ones too), or with null the visible ones.
     *
     * @param  list<string>|null  $ids
     */
    public static function pick(stdClass $spec, ?array $ids): stdClass
    {
        if (! is_array($spec->{'x-flows'} ?? null)) {
            return $spec;
        }
        $keep = $ids !== null ? array_flip($ids) : null;
        $hidden = array_flip(Settings::layout()['hidden_flows']);
        $spec->{'x-flows'} = array_values(array_filter($spec->{'x-flows'}, function ($flow) use ($keep, $hidden) {
            $id = (string) ($flow->id ?? '');

            return $keep !== null ? isset($keep[$id]) : ! isset($hidden[$id]);
        }));
        if ($spec->{'x-flows'} === []) {
            unset($spec->{'x-flows'});
        }

        return $spec;
    }

    /**
     * The flows whose every step is one of $operationIds (flows without steps are left out too).
     *
     * @param  list<array<string, mixed>>  $flows
     * @param  list<string>  $operationIds
     * @return list<array<string, mixed>>
     */
    public static function within(array $flows, array $operationIds): array
    {
        $known = array_flip($operationIds);

        return array_values(array_filter($flows, function ($flow) use ($known) {
            $steps = is_array($flow) ? (array) ($flow['steps'] ?? []) : [];
            foreach ($steps as $step) {
                if (! is_array($step) || ! isset($known[(string) ($step['operation'] ?? '')])) {
                    return false;
                }
            }

            return $steps !== [];
        }));
    }

    /**
     * Narrows a decoded document's x-flows to the operations its paths still hold.
     */
    public static function scope(stdClass $spec): stdClass
    {
        if (! isset($spec->{'x-flows'})) {
            return $spec;
        }
        $flows = self::within(json_decode(json_encode($spec->{'x-flows'}), true) ?: [], self::operationIds($spec->paths ?? []));
        if ($flows === []) {
            unset($spec->{'x-flows'});
        } else {
            $spec->{'x-flows'} = json_decode(json_encode($flows));
        }

        return $spec;
    }

    /**
     * @return list<string>
     */
    public static function operationIds(array|stdClass $paths): array
    {
        $ids = [];
        foreach ((array) json_decode(json_encode($paths), true) as $methods) {
            foreach ((array) $methods as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $ids[] = (string) $operation['operationId'];
                }
            }
        }

        return $ids;
    }

    /**
     * name => value maps (path / query values, headers); values become strings.
     *
     * @return array<string, string>
     */
    private static function pairs(mixed $pairs): array
    {
        $out = [];
        foreach (is_array($pairs) ? $pairs : [] as $name => $value) {
            if (is_string($name) && trim($name) !== '' && (is_scalar($value) || $value === null)) {
                $out[mb_substr(trim($name), 0, 100)] = mb_substr((string) $value, 0, 2000);
            }
        }

        return $out;
    }

    /**
     * @return list<array{var: string, from: string}>
     */
    private static function extracts(mixed $extracts): array
    {
        $out = [];
        foreach (is_array($extracts) ? $extracts : [] as $extract) {
            $var = is_array($extract) && is_string($extract['var'] ?? null) ? trim($extract['var']) : '';
            $from = is_array($extract) && is_string($extract['from'] ?? null) ? trim($extract['from']) : '';
            if (preg_match(self::VARIABLE, $var) && preg_match(self::SOURCE, $from) && strlen($var) <= 60 && strlen($from) <= 200) {
                $out[] = ['var' => $var, 'from' => $from];
            }
        }

        return array_slice($out, 0, 20);
    }
}
