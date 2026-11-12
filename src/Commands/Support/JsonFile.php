<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use stdClass;

/**
 * Writes an edited `composer.json` / `package.json` back the way it was found: the same
 * indentation, readable Unicode, and empty objects (`"config": {}`) still objects.
 */
final class JsonFile
{
    /**
     * @param  array<mixed>  $data  The decoded file (associative) after the edit.
     * @param  string  $original  The file's text before the edit.
     */
    public static function encode(array $data, string $original): string
    {
        $json = (string) json_encode(self::restoreEmptyObjects($data, json_decode($original)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $indent = preg_match('/^([ \t]+)"/m', $original, $matches) === 1 ? $matches[1] : '    ';

        if ($indent !== '    ') {
            $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $match): string => str_repeat($indent, intdiv(strlen($match[0]), 4)), $json);
        }

        return $json.(str_contains($original, "\r\n") ? "\r\n" : "\n");
    }

    private static function restoreEmptyObjects(mixed $value, mixed $original): mixed
    {
        if ($value === [] && $original instanceof stdClass) {
            return new stdClass;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $before = match (true) {
                $original instanceof stdClass => $original->{(string) $key} ?? null,
                is_array($original) => $original[$key] ?? null,
                default => null,
            };

            $value[$key] = self::restoreEmptyObjects($item, $before);
        }

        return $value;
    }
}
