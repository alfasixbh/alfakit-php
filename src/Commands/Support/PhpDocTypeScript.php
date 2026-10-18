<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use UnexpectedValueException;

/**
 * Turns the PHPDoc type of a `getSchemaProps()` / `getCellProps()` / `getViewData()` return
 * (`@return array{swatches: list<string>, size?: int|null}`) into a TypeScript type, for
 * `alfakit:types`. Covers the PHPStan types a JSON payload can hold: array shapes, lists,
 * `array<K, V>`, `T[]`, scalars, literals, nullables and unions; anything else is `unknown`.
 */
final class PhpDocTypeScript
{
    private int $position = 0;

    private function __construct(private readonly string $type) {}

    /** The return type in a docblock (`@phpstan-return` wins over `@return`), or null. */
    public static function returnType(string|false $docComment): ?string
    {
        if ($docComment === false) {
            return null;
        }

        $lines = array_map(
            static fn (string $line): string => (string) preg_replace('/^\s*(\/\*\*|\*\/|\*)?\s?/', '', $line),
            preg_split('/\R/', $docComment) ?: [],
        );
        $text = implode("\n", $lines);

        foreach (['@phpstan-return', '@psalm-return', '@return'] as $tag) {
            if (preg_match('/'.preg_quote($tag, '/').'\s+(.+?)(?=\n\s*@|\z)/s', $text, $match) === 1) {
                return self::leadingType(trim($match[1]));
            }
        }

        return null;
    }

    /** Convert a PHPDoc type to TypeScript. */
    public static function convert(string $type): string
    {
        $parser = new self(self::leadingType($type));

        try {
            $result = $parser->union();
        } catch (UnexpectedValueException) {
            return 'unknown';
        }

        return $parser->position < strlen($parser->type) ? 'unknown' : $result;
    }

    /** The type at the start of a tag's text, without its description. */
    private static function leadingType(string $text): string
    {
        $depth = 0;
        $quote = null;

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (str_contains('<{(', $char)) {
                $depth++;
            } elseif (str_contains('>})', $char)) {
                $depth--;
            } elseif (ctype_space($char) && $depth === 0) {
                // `int | null` keeps going; `array{…} The options` stops.
                $rest = ltrim(substr($text, $i));

                if (! str_starts_with($rest, '|') && ! str_starts_with($rest, '&') && ! preg_match('/[|&]\s*$/', substr($text, 0, $i))) {
                    return substr($text, 0, $i);
                }
            }
        }

        return $text;
    }

    private function union(): string
    {
        $types = [$this->intersection()];

        while ($this->accept('|')) {
            $types[] = $this->intersection();
        }

        $types = array_values(array_unique($types));

        return in_array('unknown', $types, true) ? 'unknown' : implode(' | ', $types);
    }

    private function intersection(): string
    {
        $types = [$this->postfix()];

        while ($this->accept('&')) {
            $types[] = $this->postfix();
        }

        return count($types) === 1 ? $types[0] : implode(' & ', array_map(self::wrap(...), $types));
    }

    private function postfix(): string
    {
        $type = $this->atom();

        while ($this->accept('[]')) {
            $type = self::wrap($type).'[]';
        }

        return $type;
    }

    private function atom(): string
    {
        $this->skipSpace();

        if ($this->accept('?')) {
            return self::wrap($this->postfix()).' | null';
        }

        if ($this->accept('(')) {
            $type = $this->union();
            $this->expect(')');

            return $type;
        }

        $char = $this->type[$this->position] ?? '';

        if ($char === "'" || $char === '"') {
            return $this->stringLiteral();
        }

        if (preg_match('/\G-?\d+(\.\d+)?/', $this->type, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);

            return $match[0];
        }

        if (preg_match('/\G\\\\?[A-Za-z_][A-Za-z0-9_\\\\-]*/', $this->type, $match, 0, $this->position) !== 1) {
            throw new UnexpectedValueException;
        }

        $this->position += strlen($match[0]);
        $name = strtolower(ltrim($match[0], '\\'));

        if (in_array($name, ['array', 'list', 'non-empty-array', 'non-empty-list'], true) && $this->accept('{')) {
            return $this->shape($name === 'list' || $name === 'non-empty-list');
        }

        if ($this->accept('<')) {
            $arguments = [$this->union()];

            while ($this->accept(',')) {
                $arguments[] = $this->union();
            }

            $this->expect('>');

            return match ($name) {
                'list', 'non-empty-list', 'iterable' => self::wrap(end($arguments)).'[]',
                'array', 'non-empty-array' => count($arguments) === 1 || in_array($arguments[0], ['number', 'int'], true)
                    ? self::wrap(end($arguments)).'[]'
                    : 'Record<string, '.end($arguments).'>',
                default => 'unknown',
            };
        }

        return match ($name) {
            'string', 'non-empty-string', 'numeric-string', 'class-string', 'literal-string', 'lowercase-string', 'non-falsy-string', 'truthy-string' => 'string',
            'int', 'integer', 'float', 'double', 'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'non-zero-int' => 'number',
            'numeric' => 'number | string',
            'bool', 'boolean' => 'boolean',
            'true' => 'true',
            'false' => 'false',
            'null', 'void' => 'null',
            'array', 'non-empty-array', 'iterable' => 'unknown[] | Record<string, unknown>',
            'list', 'non-empty-list' => 'unknown[]',
            'array-key' => 'string | number',
            'scalar' => 'string | number | boolean',
            default => 'unknown',
        };
    }

    private function shape(bool $list): string
    {
        $entries = [];
        $index = 0;

        while (! $this->accept('}')) {
            $this->skipSpace();

            // `key: type`, `key?: type`, `'quoted-key': type`, or a bare type (list items).
            $start = $this->position;
            $key = null;

            if (preg_match('/\G(?:\'([^\']*)\'|"([^"]*)"|([A-Za-z_][A-Za-z0-9_-]*|\d+))\s*(\??)\s*:(?!:)/', $this->type, $match, 0, $this->position) === 1) {
                $key = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]);
                $optional = $match[4] === '?';
                $this->position += strlen($match[0]);
            } else {
                $this->position = $start;
                $optional = false;
            }

            $value = $this->union();
            $entries[] = [$key ?? (string) $index++, $optional, $value];

            if (! $this->accept(',')) {
                $this->expect('}');

                break;
            }
        }

        if ($entries === []) {
            return $list ? '[]' : 'Record<string, never>';
        }

        $numeric = array_filter($entries, static fn (array $entry): bool => ctype_digit($entry[0])) === $entries;

        if ($list || $numeric) {
            return '['.implode(', ', array_map(static fn (array $entry): string => $entry[2].($entry[1] ? '?' : ''), $entries)).']';
        }

        return '{ '.implode('; ', array_map(
            static fn (array $entry): string => self::key($entry[0]).($entry[1] ? '?' : '').': '.$entry[2],
            $entries,
        )).' }';
    }

    private function stringLiteral(): string
    {
        $quote = $this->type[$this->position];
        $end = strpos($this->type, $quote, $this->position + 1);

        if ($end === false) {
            throw new UnexpectedValueException;
        }

        $value = substr($this->type, $this->position + 1, $end - $this->position - 1);
        $this->position = $end + 1;

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) === 1 ? $key : (string) json_encode($key, JSON_UNESCAPED_UNICODE);
    }

    private static function wrap(string $type): string
    {
        return str_contains($type, ' ') && ! str_starts_with($type, '{') && ! str_starts_with($type, 'Record<') ? "({$type})" : $type;
    }

    private function accept(string $token): bool
    {
        $this->skipSpace();

        if (substr($this->type, $this->position, strlen($token)) === $token) {
            $this->position += strlen($token);

            return true;
        }

        return false;
    }

    private function expect(string $token): void
    {
        if (! $this->accept($token)) {
            throw new UnexpectedValueException;
        }
    }

    private function skipSpace(): void
    {
        while ($this->position < strlen($this->type) && ctype_space($this->type[$this->position])) {
            $this->position++;
        }
    }
}
