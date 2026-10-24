<?php

namespace G4T\Swagger\Inference;

/**
 * Memoised access to PHP source files: raw contents, masked contents (comments / string bodies blanked
 * with identical offsets), per-line slicing, and class-name resolution through the file's namespace and
 * {@code use} imports (aliases and grouped imports included).
 */
final class PhpSource
{
    /** @var array<string, string|null> */
    private array $contents = [];

    /** @var array<string, string|null> */
    private array $masked = [];

    /** @var array<string, list<int>> */
    private array $lineOffsets = [];

    /** @var array<string, array{namespace: string, uses: array<string, string>}> */
    private array $imports = [];

    /** @var array<string, string|null> */
    private array $resolved = [];

    public function read(string $file): ?string
    {
        if (! array_key_exists($file, $this->contents)) {
            $content = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
            $this->contents[$file] = $content === false ? null : $content;
        }

        return $this->contents[$file];
    }

    public function masked(string $file): ?string
    {
        if (! array_key_exists($file, $this->masked)) {
            $content = $this->read($file);
            $this->masked[$file] = $content === null ? null : self::mask($content);
        }

        return $this->masked[$file];
    }

    /**
     * Original and masked text of lines $startLine..$endLine (1-based, inclusive).
     *
     * @return array{0: string, 1: string}|null
     */
    public function lineSlice(string $file, int $startLine, int $endLine): ?array
    {
        $content = $this->read($file);
        $masked = $this->masked($file);
        if ($content === null || $masked === null || $startLine < 1 || $endLine < $startLine) {
            return null;
        }

        if (! isset($this->lineOffsets[$file])) {
            $offsets = [0];
            $pos = 0;
            while (($pos = strpos($content, "\n", $pos)) !== false) {
                $offsets[] = ++$pos;
            }
            $this->lineOffsets[$file] = $offsets;
        }

        $offsets = $this->lineOffsets[$file];
        if (! isset($offsets[$startLine - 1])) {
            return null;
        }
        $start = $offsets[$startLine - 1];
        $end = $offsets[$endLine] ?? strlen($content);

        return [substr($content, $start, $end - $start), substr($masked, $start, $end - $start)];
    }

    /**
     * Blanks comments and string-literal contents (quotes are kept) with spaces, preserving newlines and
     * byte offsets. Works on whole files and on bare expression snippets.
     */
    public static function mask(string $code): string
    {
        $prefix = preg_match('/^\s*<\?php/i', $code) ? '' : '<?php ';
        $tokens = @token_get_all($prefix . $code);
        $out = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_ENCAPSED_AND_WHITESPACE) {
                $out .= self::blank($text);
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $quote = strcspn($text, '\'"');
                $out .= substr($text, 0, $quote + 1) . self::blank(substr($text, $quote + 1, -1)) . substr($text, -1);
            } else {
                $out .= $text;
            }
        }

        return $prefix === '' ? $out : substr($out, strlen($prefix));
    }

    private static function blank(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? str_repeat(' ', strlen($text));
    }

    /**
     * @return array{namespace: string, uses: array<string, string>} uses: lower-cased alias => FQCN
     */
    public function imports(string $file): array
    {
        if (isset($this->imports[$file])) {
            return $this->imports[$file];
        }

        $namespace = '';
        $uses = [];
        $content = $this->read($file);
        $tokens = $content === null ? [] : @token_get_all($content);
        $count = count($tokens);
        $depth = 0;
        $namespaceDepth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }

                continue;
            }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }
            if ($token[0] === T_NAMESPACE && $depth === 0) {
                [$name, $i, $braced] = $this->readNamespace($tokens, $i + 1);
                $namespace = $name;
                if ($braced) {
                    $depth++;
                    $namespaceDepth = 1;
                }

                continue;
            }
            if ($token[0] === T_USE && $depth === $namespaceDepth) {
                [$statement, $i] = $this->readStatement($tokens, $i + 1);
                foreach ($this->parseUseStatement($statement) as $alias => $fqcn) {
                    $uses[$alias] = $fqcn;
                }
            }
        }

        return $this->imports[$file] = ['namespace' => $namespace, 'uses' => $uses];
    }

    /**
     * @param  list<mixed>  $tokens
     * @return array{0: string, 1: int, 2: bool}
     */
    private function readNamespace(array $tokens, int $i): array
    {
        $name = '';
        $count = count($tokens);
        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === ';') {
                return [trim($name, '\\ '), $i, false];
            }
            if ($t === '{') {
                return [trim($name, '\\ '), $i, true];
            }
            if (is_array($t) && $t[0] !== T_WHITESPACE && $t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT) {
                $name .= $t[1];
            }
        }

        return [trim($name, '\\ '), $i, false];
    }

    /**
     * @param  list<mixed>  $tokens
     * @return array{0: string, 1: int}
     */
    private function readStatement(array $tokens, int $i): array
    {
        $text = '';
        $count = count($tokens);
        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === ';') {
                break;
            }
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $text .= $t[0] === T_WHITESPACE ? ' ' : $t[1];
            } else {
                $text .= $t;
            }
        }

        return [trim($text), $i];
    }

    /**
     * @return array<string, string> lower-cased alias => FQCN
     */
    private function parseUseStatement(string $statement): array
    {
        if ($statement === '' || $statement[0] === '(' || preg_match('/^(?:function|const)\b/i', $statement)) {
            return [];
        }

        $items = [];
        if (preg_match('/^([^{]*?)\\\\?\s*\{(.*)\}$/s', $statement, $group)) {
            $prefix = trim($group[1], '\\ ');
            foreach (explode(',', $group[2]) as $part) {
                $part = trim($part);
                if ($part !== '' && ! preg_match('/^(?:function|const)\b/i', $part)) {
                    $items[] = $prefix . '\\' . $part;
                }
            }
        } else {
            $items = explode(',', $statement);
        }

        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $item, $m)) {
                $fqcn = trim($m[1], '\\ ');
                $alias = $m[2];
            } else {
                $fqcn = trim($item, '\\ ');
                $pos = strrpos($fqcn, '\\');
                $alias = $pos === false ? $fqcn : substr($fqcn, $pos + 1);
            }
            $out[strtolower($alias)] = $fqcn;
        }

        return $out;
    }

    /**
     * Resolves a class reference as written in $file (imported, aliased, relative, fully qualified or
     * same-namespace) to an existing class / interface / enum name.
     */
    public function resolveClass(string $name, string $file): ?string
    {
        $name = trim($name);
        if ($name === '' || in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return null;
        }

        $key = $file . '|' . $name;
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $result = null;
        if ($name[0] === '\\') {
            $candidate = ltrim($name, '\\');
            $result = self::classLikeExists($candidate) ? $candidate : null;
        } else {
            $imports = $this->imports($file);
            $parts = explode('\\', $name);
            $first = strtolower($parts[0]);
            $candidates = [];
            if (isset($imports['uses'][$first])) {
                $candidates[] = $imports['uses'][$first] . (count($parts) > 1 ? '\\' . implode('\\', array_slice($parts, 1)) : '');
            }
            if ($imports['namespace'] !== '') {
                $candidates[] = $imports['namespace'] . '\\' . $name;
            }
            $candidates[] = $name;
            foreach ($candidates as $candidate) {
                if (self::classLikeExists($candidate)) {
                    $result = $candidate;

                    break;
                }
            }
        }

        return $this->resolved[$key] = $result;
    }

    public static function classLikeExists(string $name): bool
    {
        try {
            return class_exists($name) || interface_exists($name);
        } catch (\Throwable) {
            return false;
        }
    }
}
