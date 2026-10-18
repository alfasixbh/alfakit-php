<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use Illuminate\Contracts\Translation\Loader;

/**
 * Compares a locale's translations with the source language (English): which keys are
 * missing, which no longer exist, and which lost or invented a `:placeholder`.
 */
final class TranslationChecker
{
    public function __construct(
        private readonly Loader $loader,
        private readonly string $namespace = 'alfakit',
        private readonly string $sourceLocale = 'en',
    ) {}

    /**
     * @return array<string, string> dotted key (`group.key`) => source text
     */
    public function source(): array
    {
        return $this->lines($this->sourceLocale);
    }

    /**
     * @return array<string, string>
     */
    public function lines(string $locale): array
    {
        $lines = [];

        foreach (Translations::groups($this->namespace, $this->sourceLocale) as $group) {
            foreach (self::flatten($this->loader->load($locale, $group, $this->namespace)) as $key => $value) {
                $lines["{$group}.{$key}"] = $value;
            }
        }

        return $lines;
    }

    /**
     * @return array{total: int, missing: array<int, string>, extra: array<int, string>, placeholders: array<int, string>}
     */
    public function check(string $locale): array
    {
        $source = $this->source();
        $target = $this->lines($locale);

        $missing = [];
        $placeholders = [];

        foreach ($source as $key => $text) {
            if (! isset($target[$key]) || trim($target[$key]) === '') {
                $missing[] = $key;

                continue;
            }

            if (self::placeholders($text) !== self::placeholders($target[$key])) {
                $placeholders[] = $key;
            }
        }

        return [
            'total' => count($source),
            'missing' => $missing,
            'extra' => array_values(array_diff(array_keys($target), array_keys($source))),
            'placeholders' => $placeholders,
        ];
    }

    /**
     * Placeholder names used in a line, lower-cased (`:Name` and `:NAME` are the same
     * placeholder in Laravel), sorted and unique.
     *
     * @return array<int, string>
     */
    public static function placeholders(string $line): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $matches);

        $names = array_unique(array_map('strtolower', $matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @param  array<mixed>  $lines
     * @return array<string, string>
     */
    public static function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, "{$prefix}{$key}.");
            } elseif (is_scalar($value)) {
                $flat["{$prefix}{$key}"] = (string) $value;
            }
        }

        return $flat;
    }
}
