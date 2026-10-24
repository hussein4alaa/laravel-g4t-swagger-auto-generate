<?php

namespace G4T\Swagger\Inference;

/**
 * Structural helpers operating on "masked" PHP source (comments blanked and string contents replaced
 * by spaces, see {@see PhpSource::mask()}), so brackets, keywords and separators inside strings or
 * comments are never mistaken for code. Masked offsets are identical to the original source offsets.
 */
final class CodeScanner
{
    private const OPENERS = ['(' => true, '[' => true, '{' => true];

    private const CLOSERS = [')' => true, ']' => true, '}' => true];

    /**
     * Index of the bracket closing the one at $open ((), [] or {}), or null when unbalanced.
     */
    public static function matchClose(string $masked, int $open): ?int
    {
        $len = strlen($masked);
        if ($open < 0 || $open >= $len || ! isset(self::OPENERS[$masked[$open]])) {
            return null;
        }

        $depth = 0;
        for ($i = $open; $i < $len; $i++) {
            $i += strcspn($masked, '()[]{}', $i);
            if ($i >= $len) {
                break;
            }
            if (isset(self::OPENERS[$masked[$i]])) {
                $depth++;

                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $i;
            }
            if ($depth < 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * Splits [$start, $end) at top-level occurrences of $separator.
     *
     * @return list<array{0: int, 1: int}> [start, end) ranges
     */
    public static function splitTopLevel(string $masked, int $start, int $end, string $separator = ','): array
    {
        $parts = [];
        $depth = 0;
        $segmentStart = $start;
        $sepLen = strlen($separator);
        $first = $separator[0];

        for ($i = $start; $i < $end; $i++) {
            $c = $masked[$i];
            if (isset(self::OPENERS[$c])) {
                $depth++;

                continue;
            }
            if (isset(self::CLOSERS[$c])) {
                $depth--;

                continue;
            }
            if ($depth !== 0 || $c !== $first || substr($masked, $i, $sepLen) !== $separator) {
                continue;
            }
            if ($separator === '=>' && $i > 0 && $masked[$i - 1] === '<') {
                continue;
            }
            $parts[] = [$segmentStart, $i];
            $i += $sepLen - 1;
            $segmentStart = $i + 1;
        }
        $parts[] = [$segmentStart, $end];

        return $parts;
    }

    /**
     * Index of the first top-level terminator char at/after $start, or of an unmatched closing bracket,
     * or the string length.
     */
    public static function expressionEnd(string $masked, int $start, string $terminators = ';'): int
    {
        $len = strlen($masked);
        $depth = 0;
        for ($i = $start; $i < $len; $i++) {
            $c = $masked[$i];
            if (isset(self::OPENERS[$c])) {
                $depth++;
            } elseif (isset(self::CLOSERS[$c])) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($depth === 0 && str_contains($terminators, $c)) {
                return $i;
            }
        }

        return $len;
    }

    /**
     * Inclusive [start, end] ranges of closures and arrow functions starting at/after $from.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function closureRanges(string $masked, int $from): array
    {
        $ranges = [];
        if (! preg_match_all('/(?<![\w$>:\\\\])(function|fn)\s*&?\s*\(/', $masked, $m, PREG_OFFSET_CAPTURE, $from)) {
            return [];
        }

        foreach ($m[0] as $i => [$text, $pos]) {
            $close = self::matchClose($masked, $pos + strlen($text) - 1);
            if ($close === null) {
                continue;
            }
            if ($m[1][$i][0] === 'function') {
                $brace = strpos($masked, '{', $close);
                $end = $brace === false ? null : self::matchClose($masked, $brace);
                if ($end !== null) {
                    $ranges[] = [$pos, $end];
                }

                continue;
            }
            $arrow = strpos($masked, '=>', $close);
            if ($arrow === false) {
                continue;
            }
            $ranges[] = [$pos, self::expressionEnd($masked, $arrow + 2, ',;') - 1];
        }

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    public static function inRanges(int $pos, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($pos >= $start && $pos <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arguments of the call whose "(" is at $open. Named arguments ({@code status: 422}) are reported
     * with their name and the "name:" prefix removed.
     *
     * @return list<array{orig: string, masked: string, name: ?string, offset: int}>
     */
    public static function arguments(string $orig, string $masked, int $open): array
    {
        $close = self::matchClose($masked, $open);
        if ($close === null) {
            return [];
        }

        $args = [];
        foreach (self::splitTopLevel($masked, $open + 1, $close) as [$start, $end]) {
            $m = substr($masked, $start, $end - $start);
            $lead = strlen($m) - strlen(ltrim($m));
            $m = trim($m);
            if ($m === '') {
                continue;
            }
            $o = substr($orig, $start + $lead, strlen($m));
            $name = null;
            if (preg_match('/^([A-Za-z_]\w*)\s*:(?!:)/', $m, $nm)) {
                $name = $nm[1];
                $cut = strlen($nm[0]);
                $rest = substr($m, $cut);
                $restLead = strlen($rest) - strlen(ltrim($rest));
                $lead += $cut + $restLead;
                $m = ltrim($rest);
                $o = substr($o, $cut + $restLead);
            }
            $args[] = ['orig' => $o, 'masked' => $m, 'name' => $name, 'offset' => $start + $lead];
        }

        return $args;
    }

    /**
     * Picks a call argument by name or position.
     *
     * @param  list<array{orig: string, masked: string, name: ?string, offset: int}>  $args
     * @return array{orig: string, masked: string, name: ?string, offset: int}|null
     */
    public static function argument(array $args, int $position, string $name): ?array
    {
        foreach ($args as $arg) {
            if ($arg['name'] === $name) {
                return $arg;
            }
        }

        $positional = array_values(array_filter($args, fn (array $a) => $a['name'] === null));

        return $positional[$position] ?? null;
    }

    /**
     * Top-level ternary / null-coalescing split of an expression.
     *
     * @return array{type: string, parts: list<array{0: int, 1: int}>}|null type is ternary|short|coalesce
     */
    public static function splitConditional(string $masked): ?array
    {
        $len = strlen($masked);
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $masked[$i];
            if (isset(self::OPENERS[$c])) {
                $depth++;

                continue;
            }
            if (isset(self::CLOSERS[$c])) {
                $depth--;

                continue;
            }
            if ($depth !== 0 || $c !== '?') {
                continue;
            }
            $next = $masked[$i + 1] ?? '';
            if ($next === '-' && ($masked[$i + 2] ?? '') === '>') {
                $i += 2;

                continue;
            }
            if ($next === '?') {
                return ['type' => 'coalesce', 'parts' => [[0, $i], [$i + 2, $len]]];
            }
            if ($next === ':') {
                return ['type' => 'short', 'parts' => [[0, $i], [$i + 2, $len]]];
            }
            $colon = self::ternaryColon($masked, $i + 1);
            if ($colon === null) {
                return null;
            }

            return ['type' => 'ternary', 'parts' => [[0, $i], [$i + 1, $colon], [$colon + 1, $len]]];
        }

        return null;
    }

    private static function ternaryColon(string $masked, int $from): ?int
    {
        $len = strlen($masked);
        $depth = 0;
        $nested = 0;
        for ($i = $from; $i < $len; $i++) {
            $c = $masked[$i];
            if (isset(self::OPENERS[$c])) {
                $depth++;

                continue;
            }
            if (isset(self::CLOSERS[$c])) {
                $depth--;

                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($c === '?') {
                $next = $masked[$i + 1] ?? '';
                if ($next === '-' || $next === '?' || $next === ':') {
                    $i++;

                    continue;
                }
                $nested++;

                continue;
            }
            if ($c === ':') {
                if (($masked[$i + 1] ?? '') === ':') {
                    $i++;

                    continue;
                }
                if ($nested === 0) {
                    return $i;
                }
                $nested--;
            }
        }

        return null;
    }
}
