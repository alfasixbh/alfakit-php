<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Singular and plural labels for an Eloquent model, in the current language.
 *
 * English labels are generated from the class name (`BlogPost` → "blog post" / "blog
 * posts", `Category` → "category" / "categories", `Person` → "person" / "people").
 * Every other language — or an English name the inflector gets wrong — is set in the
 * app's `models` translation group, keyed by the snake-cased class name:
 *
 * ```php
 * // lang/ar/models.php
 * return [
 *     'category' => ['singular' => 'تصنيف', 'plural' => 'تصنيفات'],
 *     'blog_post' => 'تدوينة|تدوينات',   // shorthand: singular|plural
 * ];
 * ```
 */
final class ModelLabel
{
    /** Translation key for a model: `App\Models\BlogPost` → `blog_post`. */
    public static function key(string $class): string
    {
        return Str::snake(class_basename($class));
    }

    /** English singular, used for URLs and relationship names: never translated. */
    public static function guess(string $class): string
    {
        return Labels::fromClass($class);
    }

    public static function singular(string $class): string
    {
        return self::translated($class)['singular'] ?? self::guess($class);
    }

    public static function plural(string $class): string
    {
        $translated = self::translated($class);

        if (isset($translated['plural'])) {
            return $translated['plural'];
        }

        if (isset($translated['singular'])) {
            return self::pluralizeOverride($translated['singular']);
        }

        return Labels::pluralize(self::guess($class), 'en');
    }

    /**
     * Plural of a label a developer set by hand (`$modelLabel`): pluralised with the
     * current language's rules when they exist, otherwise English when the label looks
     * English, otherwise left as is.
     */
    public static function pluralizeOverride(string $label): string
    {
        $locale = app()->getLocale();

        if (Labels::canPluralize($locale)) {
            return Labels::pluralize($label, $locale);
        }

        return preg_match('/^[\x20-\x7E]+$/', $label) === 1 ? Labels::pluralize($label, 'en') : $label;
    }

    /**
     * @return array{singular?: string, plural?: string}
     */
    private static function translated(string $class): array
    {
        $key = self::group().'.'.self::key($class);

        if (! Lang::has($key)) {
            return [];
        }

        $line = __($key);

        if (is_array($line)) {
            return array_filter([
                'singular' => is_string($line['singular'] ?? null) ? $line['singular'] : null,
                'plural' => is_string($line['plural'] ?? null) ? $line['plural'] : null,
            ]);
        }

        if (! is_string($line) || $line === '') {
            return [];
        }

        $parts = array_map('trim', explode('|', $line));

        return array_filter(['singular' => $parts[0], 'plural' => $parts[1] ?? null]);
    }

    private static function group(): string
    {
        return (string) config('alfakit.model_labels', 'models');
    }
}
