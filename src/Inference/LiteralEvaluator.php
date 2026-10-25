<?php

namespace G4T\Swagger\Inference;

use BackedEnum;
use Closure;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use UnitEnum;

/**
 * Safe, token-based evaluator for PHP literal expressions (never executes the code).
 *
 * Supports ints, floats, strings (with escapes and concatenation of literals), true/false/null, short and
 * long array syntax (nested, keyed, spread), casts of literals, {@code __('key')} / {@code trans('key')},
 * {@code Foo::class}, class constants and enum cases (BackedEnum → value, pure enum → name).
 *
 * A variable is resolved through the optional variable resolver (parameters bound to the caller's
 * arguments when a call is followed); anything it does not know stays non-literal.
 *
 * Every sub-expression that is not a literal is handed to the optional fallback (source text plus the
 * array key it belongs to), which returns a placeholder example. Without a fallback, {@see Unresolved}
 * is returned instead.
 */
final class LiteralEvaluator
{
    private const OPENERS = ['(' => true, '[' => true, '{' => true];

    private const CLOSERS = [')' => true, ']' => true, '}' => true];

    /**
     * @param  (Closure(string): ?string)|null  $classResolver  resolves a class reference as written in the source
     * @param  (Closure(string, ?string): mixed)|null  $fallback  example for a non-literal expression and its array key
     * @param  (Closure(string): mixed)|null  $variableResolver  value of a variable (name without "$"), or {@see Unresolved}
     */
    public function __construct(
        private ?Closure $classResolver = null,
        private ?Closure $fallback = null,
        private ?Closure $variableResolver = null,
    ) {
    }

    /**
     * Evaluates $code; non-literal parts go through the fallback (or become {@see Unresolved}).
     */
    public function evaluate(string $code, ?string $key = null): mixed
    {
        return $this->evalTokens($this->tokenize($code), $key, false);
    }

    /**
     * Evaluates $code as a pure literal; returns {@see Unresolved} when any part is not a literal.
     */
    public function evaluateStrict(string $code): mixed
    {
        return $this->evalTokens($this->tokenize($code), null, true);
    }

    /**
     * @return list<array{0: int|string, 1: string, 2: string}> [id, text, preceding whitespace]
     */
    private function tokenize(string $code): array
    {
        $raw = @token_get_all('<?php ' . $code);
        array_shift($raw);
        $out = [];
        $ws = '';
        foreach ($raw as $token) {
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $ws .= $token[1];

                    continue;
                }
                $out[] = [$token[0], $token[1], $ws];
            } else {
                $out[] = [$token, $token, $ws];
            }
            $ws = '';
        }

        return $out;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     */
    private function source(array $tokens): string
    {
        $s = '';
        foreach ($tokens as $i => $t) {
            $s .= ($i === 0 ? '' : $t[2]) . $t[1];
        }

        return $s;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     */
    private function fail(array $tokens, ?string $key, bool $strict): mixed
    {
        if ($strict || $this->fallback === null) {
            return Unresolved::instance();
        }

        return ($this->fallback)($this->source($tokens), $key);
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     */
    private function evalTokens(array $tokens, ?string $key, bool $strict): mixed
    {
        $tokens = $this->stripParentheses($tokens);
        if ($tokens === []) {
            return $this->fail($tokens, $key, $strict);
        }

        $parts = $this->split($tokens, '.');
        if (count($parts) > 1) {
            $s = '';
            foreach ($parts as $part) {
                $v = $this->evalTokens($part, null, true);
                if (! is_scalar($v) && $v !== null) {
                    return $this->fail($tokens, $key, $strict);
                }
                $s .= is_bool($v) ? ($v ? '1' : '') : (string) $v;
            }

            return $s;
        }

        [$value, $next] = $this->term($tokens, 0, $key, $strict);
        if ($value instanceof Unresolved || $next !== count($tokens)) {
            return $this->fail($tokens, $key, $strict);
        }

        return $value;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     * @return array{0: mixed, 1: int} value (or Unresolved) and index of the next token
     */
    private function term(array $tokens, int $i, ?string $key, bool $strict): array
    {
        $unresolved = [Unresolved::instance(), $i];
        $t = $tokens[$i] ?? null;
        if ($t === null) {
            return $unresolved;
        }
        $id = $t[0];
        $next = $tokens[$i + 1] ?? null;

        if ($id === T_LNUMBER) {
            return [$this->intValue($t[1]), $i + 1];
        }
        if ($id === T_DNUMBER) {
            return [(float) str_replace('_', '', $t[1]), $i + 1];
        }
        if (($id === '-' || $id === '+') && $next !== null && ($next[0] === T_LNUMBER || $next[0] === T_DNUMBER)) {
            [$v, $n] = $this->term($tokens, $i + 1, null, true);

            return [$id === '-' ? -$v : $v, $n];
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            return [$this->decodeString($t[1]), $i + 1];
        }
        if ($id === T_VARIABLE && $this->variableResolver !== null) {
            return [($this->variableResolver)(substr($t[1], 1)), $i + 1];
        }
        if ($id === '[') {
            $close = $this->matchClose($tokens, $i);
            if ($close === null) {
                return $unresolved;
            }

            return [$this->arrayValue(array_slice($tokens, $i + 1, $close - $i - 1), $strict), $close + 1];
        }
        if ($id === T_ARRAY && $next !== null && $next[0] === '(') {
            $close = $this->matchClose($tokens, $i + 1);
            if ($close === null) {
                return $unresolved;
            }

            return [$this->arrayValue(array_slice($tokens, $i + 2, $close - $i - 2), $strict), $close + 1];
        }
        if (in_array($id, [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST, T_STRING_CAST], true)) {
            [$v, $n] = $this->term($tokens, $i + 1, null, true);
            if (! is_scalar($v) && $v !== null) {
                return $unresolved;
            }

            return [match ($id) {
                T_INT_CAST => (int) $v,
                T_DOUBLE_CAST => (float) $v,
                T_BOOL_CAST => (bool) $v,
                default => (string) $v,
            }, $n];
        }
        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC], true)) {
            $lower = strtolower(ltrim($t[1], '\\'));
            if ($id === T_STRING && in_array($lower, ['true', 'false', 'null'], true)) {
                return [match ($lower) {
                    'true' => true,
                    'false' => false,
                    default => null,
                }, $i + 1];
            }
            if ($next !== null && $next[0] === T_DOUBLE_COLON) {
                $member = $tokens[$i + 2] ?? null;
                if ($member !== null && ($member[0] === T_STRING || $member[0] === T_CLASS)
                    && ($tokens[$i + 3][0] ?? null) !== '(') {
                    return [$this->classConstant($t[1], $member[1]), $i + 3];
                }

                return $unresolved;
            }
            if ($next !== null && $next[0] === '(' && in_array($lower, ['__', 'trans', 'trans_choice'], true)) {
                $close = $this->matchClose($tokens, $i + 1);
                if ($close === null) {
                    return $unresolved;
                }
                $args = $this->split(array_slice($tokens, $i + 2, $close - $i - 2), ',');
                $message = $this->evalTokens($args[0] ?? [], null, true);
                if (! is_string($message)) {
                    return $unresolved;
                }

                return [$this->translate($message), $close + 1];
            }
        }

        return $unresolved;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens  tokens between the array brackets
     */
    private function arrayValue(array $tokens, bool $strict): mixed
    {
        $out = [];
        foreach ($this->split($tokens, ',') as $element) {
            if ($element === []) {
                continue;
            }
            if ($element[0][0] === T_ELLIPSIS) {
                $spread = $this->evalTokens(array_slice($element, 1), null, $strict);
                if (! is_array($spread)) {
                    if ($strict) {
                        return Unresolved::instance();
                    }

                    continue;
                }
                foreach ($spread as $k => $v) {
                    if (is_int($k)) {
                        $out[] = $v;
                    } else {
                        $out[$k] = $v;
                    }
                }

                continue;
            }

            $pair = $this->split($element, T_DOUBLE_ARROW, 1);
            if (count($pair) === 2) {
                $k = $this->evalTokens($pair[0], null, true);
                if (is_bool($k) || is_float($k)) {
                    $k = (int) $k;
                } elseif ($k === null) {
                    $k = '';
                } elseif (! is_int($k) && ! is_string($k)) {
                    if ($strict) {
                        return Unresolved::instance();
                    }
                    $k = 'key';
                }
                $v = $this->evalTokens($pair[1], (string) $k, $strict);
                if ($strict && $v instanceof Unresolved) {
                    return $v;
                }
                $out[$k] = $v;

                continue;
            }

            $v = $this->evalTokens($element, null, $strict);
            if ($strict && $v instanceof Unresolved) {
                return $v;
            }
            $out[] = $v;
        }

        return $out;
    }

    private function classConstant(string $class, string $member): mixed
    {
        $fqcn = null;
        try {
            if ($this->classResolver !== null) {
                $fqcn = ($this->classResolver)($class);
            } elseif (PhpSource::classLikeExists(ltrim($class, '\\'))) {
                $fqcn = ltrim($class, '\\');
            }

            if ($fqcn === null) {
                if (str_starts_with($member, 'HTTP_') && defined(SymfonyResponse::class . '::' . $member)) {
                    return constant(SymfonyResponse::class . '::' . $member);
                }

                return Unresolved::instance();
            }

            if (strtolower($member) === 'class') {
                return $fqcn;
            }

            $constant = $fqcn . '::' . $member;
            if (! defined($constant)) {
                return Unresolved::instance();
            }
            $value = constant($constant);
            if ($value instanceof BackedEnum) {
                return $value->value;
            }
            if ($value instanceof UnitEnum) {
                return $value->name;
            }
            if (is_scalar($value) || $value === null || (is_array($value) && ! Unresolved::containedIn($value))) {
                return $value;
            }
        } catch (Throwable) {
            // Unknown or unloadable class: not a literal.
        }

        return Unresolved::instance();
    }

    private function translate(string $key): string
    {
        try {
            if (function_exists('__')) {
                $translated = __($key);
                if (is_string($translated)) {
                    return $translated;
                }
            }
        } catch (Throwable) {
            // No translator bound: fall back to the key itself.
        }

        return $key;
    }

    private function intValue(string $literal): int
    {
        $literal = strtolower(str_replace('_', '', $literal));
        if (str_starts_with($literal, '0x')) {
            return (int) hexdec(substr($literal, 2));
        }
        if (str_starts_with($literal, '0b')) {
            return (int) bindec(substr($literal, 2));
        }
        if (str_starts_with($literal, '0o')) {
            return (int) octdec(substr($literal, 2));
        }
        if (strlen($literal) > 1 && $literal[0] === '0') {
            return (int) octdec($literal);
        }

        return (int) $literal;
    }

    private function decodeString(string $literal): string
    {
        $quotePos = strcspn($literal, '\'"');
        $quote = $literal[$quotePos];
        $inner = substr($literal, $quotePos + 1, -1);

        if ($quote === "'") {
            return preg_replace_callback('/\\\\([\\\\\'])/', fn (array $m) => $m[1], $inner) ?? $inner;
        }

        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];

        return preg_replace_callback(
            '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            function (array $m) use ($map): string {
                if (($m[1] ?? '') !== '') {
                    return $map[$m[1]];
                }
                if (($m[2] ?? '') !== '') {
                    return chr(octdec($m[2]) & 255);
                }
                if (($m[3] ?? '') !== '') {
                    return chr(hexdec($m[3]));
                }

                return mb_chr((int) hexdec($m[4]), 'UTF-8') ?: '';
            },
            $inner
        ) ?? $inner;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     * @return list<array{0: int|string, 1: string, 2: string}>
     */
    private function stripParentheses(array $tokens): array
    {
        while ($tokens !== [] && $tokens[0][0] === '(' && $this->matchClose($tokens, 0) === count($tokens) - 1) {
            $tokens = array_slice($tokens, 1, -1);
        }

        return $tokens;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     */
    private function matchClose(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($this->isOpener($tokens[$i][0])) {
                $depth++;
            } elseif (isset(self::CLOSERS[$tokens[$i][0]])) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function isOpener(int|string $id): bool
    {
        return (is_string($id) && isset(self::OPENERS[$id]))
            || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $id === T_ATTRIBUTE;
    }

    /**
     * Splits tokens at top-level separators (token id), at most $limit times when $limit > 0.
     *
     * @param  list<array{0: int|string, 1: string, 2: string}>  $tokens
     * @return list<list<array{0: int|string, 1: string, 2: string}>>
     */
    private function split(array $tokens, int|string $separator, int $limit = 0): array
    {
        $parts = [];
        $current = [];
        $depth = 0;
        foreach ($tokens as $t) {
            if ($this->isOpener($t[0])) {
                $depth++;
            } elseif (is_string($t[0]) && isset(self::CLOSERS[$t[0]])) {
                $depth--;
            } elseif ($depth === 0 && $t[0] === $separator && ($limit === 0 || count($parts) < $limit)) {
                $parts[] = $current;
                $current = [];

                continue;
            }
            $current[] = $t;
        }
        $parts[] = $current;

        return $parts;
    }
}
