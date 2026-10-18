<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

/**
 * A small Composer-style version constraint matcher, so `alfakit:upgrade` can check plugin
 * constraints without depending on `composer/semver` at runtime.
 *
 * Supported: `*`, exact versions (`1.2.3`, `v1.2`), caret (`^1.2`), tilde (`~1.2`),
 * wildcards (`1.*`, `1.2.x`), comparison operators (`>=1.0`, `<2.0`, `!=1.5`), ranges
 * joined with spaces or commas (AND) and alternatives joined with `||` (OR). Stability
 * suffixes (`1.0.0-beta1`, `@dev`) are ignored for the comparison.
 */
final class VersionConstraint
{
    /**
     * Whether `$version` satisfies `$constraint`. Returns null when the question cannot be
     * answered: a version without a numeric major (`dev-main`) or an unparsable constraint.
     */
    public static function satisfies(string $version, string $constraint): ?bool
    {
        $parsed = self::parse($version);

        if ($parsed === null) {
            return null;
        }

        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [];
        $sawValidAlternative = false;

        foreach ($alternatives as $alternative) {
            $result = self::satisfiesAll($parsed, $alternative);

            if ($result === true) {
                return true;
            }

            if ($result === false) {
                $sawValidAlternative = true;
            }
        }

        return $sawValidAlternative ? false : null;
    }

    /**
     * The [major, minor, patch] of a version, or null for branch names and the like.
     *
     * @return array{int, int, int}|null
     */
    public static function parse(string $version): ?array
    {
        $version = preg_replace('/^v/i', '', trim($version)) ?? '';
        $version = preg_replace('/[-+@].*$/', '', $version) ?? '';

        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?$/', $version, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) ($matches[2] ?? 0), (int) ($matches[3] ?? 0)];
    }

    /**
     * @param  array{int, int, int}  $version
     */
    private static function satisfiesAll(array $version, string $constraints): ?bool
    {
        $constraints = trim($constraints);

        if ($constraints === '' || $constraints === '*') {
            return true;
        }

        $parts = preg_split('/\s*,\s*|\s+/', $constraints) ?: [];

        foreach ($parts as $part) {
            $part = preg_replace('/@.*$/', '', $part) ?? '';

            if ($part === '') {
                continue;
            }

            $result = self::satisfiesOne($version, $part);

            if ($result !== true) {
                return $result;
            }
        }

        return true;
    }

    /**
     * @param  array{int, int, int}  $version
     */
    private static function satisfiesOne(array $version, string $constraint): ?bool
    {
        if (preg_match('/^(\^|~|>=|<=|>|<|!=|==?)?\s*v?(.+)$/', $constraint, $matches) !== 1) {
            return null;
        }

        $operator = $matches[1];
        $operand = $matches[2];

        if (in_array($operand, ['*', 'x', 'X'], true)) {
            return true;
        }

        $wildcard = preg_match('/^(\d+)(?:\.(\d+))?\.(?:\*|x|X)$/', $operand, $wild) === 1;

        if ($wildcard) {
            $lower = [(int) $wild[1], (int) ($wild[2] ?? 0), 0];
            $upper = ($wild[2] ?? '') === '' ? [$lower[0] + 1, 0, 0] : [$lower[0], $lower[1] + 1, 0];

            return self::compare($version, $lower) >= 0 && self::compare($version, $upper) < 0;
        }

        $operandVersion = self::parse($operand);

        if ($operandVersion === null) {
            return null;
        }

        $explicitParts = count(explode('.', (string) preg_replace('/[-+@].*$/', '', (string) preg_replace('/^v/i', '', $operand))));

        return match ($operator) {
            '^' => self::compare($version, $operandVersion) >= 0 && self::compare($version, self::caretUpper($operandVersion)) < 0,
            '~' => self::compare($version, $operandVersion) >= 0 && self::compare($version, self::tildeUpper($operandVersion, $explicitParts)) < 0,
            '>=' => self::compare($version, $operandVersion) >= 0,
            '<=' => self::compare($version, $operandVersion) <= 0,
            '>' => self::compare($version, $operandVersion) > 0,
            '<' => self::compare($version, $operandVersion) < 0,
            '!=' => self::compare($version, $operandVersion) !== 0,
            default => self::compare($version, $operandVersion) === 0,
        };
    }

    /**
     * `^1.2.3` allows `<2.0.0`; `^0.2.3` allows `<0.3.0`; `^0.0.3` allows `<0.0.4`.
     *
     * @param  array{int, int, int}  $version
     * @return array{int, int, int}
     */
    private static function caretUpper(array $version): array
    {
        if ($version[0] > 0) {
            return [$version[0] + 1, 0, 0];
        }

        if ($version[1] > 0) {
            return [0, $version[1] + 1, 0];
        }

        return [0, 0, $version[2] + 1];
    }

    /**
     * `~1.2` allows `<2.0.0`; `~1.2.3` allows `<1.3.0`.
     *
     * @param  array{int, int, int}  $version
     * @return array{int, int, int}
     */
    private static function tildeUpper(array $version, int $explicitParts): array
    {
        return $explicitParts >= 3 ? [$version[0], $version[1] + 1, 0] : [$version[0] + 1, 0, 0];
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
