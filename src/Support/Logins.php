<?php

namespace G4T\Swagger\Support;

/**
 * Try it out logins (swagger.auto_login): one login or a list of them, each made of one request or of several steps
 * (send a code, then verify it). A step posts its form fields plus the values of "send", where `{{name}}` is an earlier
 * field or a value an earlier step kept from its response with "extract" ({var, from}, like a flow step). The token is
 * read from the last step's response.
 */
final class Logins
{
    public const MAX_STEPS = 10;

    /** Field names sent in a step's JSON body. */
    public const FIELD = '/^[A-Za-z0-9_.-]+$/';

    /**
     * The steps of one login, well formed: a login without "steps" is its own single step. Steps without a URL are
     * left out, so a login with no URL at all has no steps.
     *
     * @param  array<string, mixed>  $login
     * @return list<array{name: string, url: string, method: string, fields: array<string, string>, prefill: array<string, string>, send: array<string, string>, extract: list<array{var: string, from: string}>}>
     */
    public static function steps(array $login): array
    {
        $stepped = is_array($login['steps'] ?? null) && $login['steps'] !== [];
        $rows = $stepped ? array_values($login['steps']) : [$login];
        $single = count($rows) === 1;
        $steps = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['url'] ?? null) || trim($row['url']) === '' || count($steps) >= self::MAX_STEPS) {
                continue;
            }
            $fields = self::pairs($row['fields'] ?? null);
            $steps[] = [
                // Without steps the row is the login itself, and its name is not a step's.
                'name' => $stepped && is_string($row['name'] ?? null) ? trim($row['name']) : '',
                'url' => trim($row['url']),
                'method' => strtoupper(is_string($row['method'] ?? null) && $row['method'] !== '' ? $row['method'] : 'POST'),
                // A lone step without fields keeps the classic email + password form; a step of several may have none.
                'fields' => $fields !== [] || ! $single ? $fields : ['email' => 'Email', 'password' => 'Password'],
                'prefill' => self::pairs($row['prefill'] ?? null),
                'send' => self::pairs($row['send'] ?? null),
                'extract' => self::extracts($row['extract'] ?? null),
            ];
        }

        return $steps;
    }

    /**
     * name => value maps with usable field names; values become strings.
     *
     * @return array<string, string>
     */
    private static function pairs(mixed $pairs): array
    {
        $out = [];
        foreach (is_array($pairs) ? $pairs : [] as $name => $value) {
            if (is_string($name) && preg_match(self::FIELD, $name) && (is_scalar($value) || $value === null)) {
                $out[$name] = (string) $value;
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
            if (preg_match(Flows::VARIABLE, $var) && preg_match(Flows::SOURCE, $from)) {
                $out[] = ['var' => $var, 'from' => $from];
            }
        }

        return array_slice($out, 0, 20);
    }
}
