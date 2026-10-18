<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Illuminate\Support\Str;

/**
 * Turns code names into human labels, and labels into plurals, the same way everywhere.
 *
 * ```php
 * Labels::fromName('author_id');      // "Author"
 * Labels::fromName('tags.name');      // "Tags"
 * Labels::fromName('author.email');   // "Author email"
 * Labels::fromName('cover_image_url'); // "Cover image URL"
 * Labels::pluralize('category');      // "categories"
 * Labels::pluralize('catégorie', 'fr'); // "catégories"
 * ```
 */
final class Labels
{
    /** Words written in capitals inside generated labels. */
    private const ACRONYMS = [
        'api', 'cc', 'css', 'csv', 'ean', 'faq', 'gps', 'html', 'iban', 'id', 'ip', 'isbn', 'json',
        'otp', 'pdf', 'pin', 'seo', 'sku', 'sms', 'ssn', 'swift', 'uri', 'url', 'utc', 'uuid', 'ulid',
        'upc', 'vat', 'vin', 'xml',
    ];

    /** Attributes that just name a related record: `author.name` reads as "Author". */
    private const TITLE_ATTRIBUTES = ['name', 'title', 'label'];

    /** Languages Laravel's inflector (Doctrine) has plural rules for. */
    private const INFLECTOR_LANGUAGES = [
        'en' => 'english',
        'fr' => 'french',
        'es' => 'spanish',
        'pt' => 'portuguese',
        'tr' => 'turkish',
        'nb' => 'norwegian-bokmal',
        'no' => 'norwegian-bokmal',
    ];

    /** Languages that capitalise nouns, so labels keep their case inside sentences. */
    private const NOUN_CAPITALISING_LANGUAGES = ['de', 'lb'];

    /** @var array<string, Inflector> */
    private static array $inflectors = [];

    /**
     * A label for a field, column, filter, entry or action name.
     *
     * Dots mean different things: in tables, filters and infolists they walk
     * relationships (`author.email` → "Author email", `tags.name` → "Tags"); in forms
     * they nest state (`meta.og_title` → "Og title"), so only the last part is used.
     */
    public static function fromName(string $name, bool $isRelationshipPath = true): string
    {
        $segments = array_values(array_filter(explode('.', $name), static fn (string $part): bool => $part !== ''));

        if (! $isRelationshipPath && $segments !== []) {
            $segments = [end($segments)];
        }

        if (count($segments) > 1 && in_array(strtolower((string) end($segments)), self::TITLE_ATTRIBUTES, true)) {
            array_pop($segments);
        }

        $words = [];

        foreach ($segments as $segment) {
            $segment = (string) preg_replace('/(_id|Id|_uuid|_ulid)$/', '', $segment);

            if ($segment === '') {
                $segment = 'id';
            }

            foreach (explode(' ', Str::of($segment)->snake()->replace(['-', '_'], ' ')->squish()->lower()->toString()) as $word) {
                $words[] = in_array($word, self::ACRONYMS, true) ? strtoupper($word) : $word;
            }
        }

        return Str::ucfirst(implode(' ', $words));
    }

    /**
     * The English, lower-case singular label for a class: `BlogPost` → "blog post",
     * `SeoPage` → "SEO page".
     */
    public static function fromClass(string $class): string
    {
        return self::lowerFirstWord(self::fromName(class_basename($class)));
    }

    /**
     * Plural of a label in a language. Uses Doctrine's rules where they exist (English,
     * French, Spanish, Portuguese, Turkish, Norwegian); for other languages the label is
     * returned unchanged rather than guessing, so translate those plurals explicitly.
     * Multi-word labels pluralise their last word, and letter case is preserved.
     */
    public static function pluralize(string $label, ?string $locale = null): string
    {
        $inflector = self::inflector($locale ?? 'en');

        if ($inflector === null || trim($label) === '') {
            return $label;
        }

        $words = explode(' ', $label);
        $last = (string) array_pop($words);

        // Acronyms ("FAQ") and words with digits are left alone.
        if ($last === strtoupper($last) && strlen($last) > 1 || preg_match('/\d/', $last) === 1) {
            return $label;
        }

        $plural = $inflector->pluralize($last);

        if ($last !== '' && mb_strtoupper(mb_substr($last, 0, 1)) === mb_substr($last, 0, 1)) {
            $plural = Str::ucfirst($plural);
        }

        return implode(' ', [...$words, $plural]);
    }

    public static function canPluralize(string $locale): bool
    {
        return self::inflector($locale) !== null;
    }

    /**
     * A label as it reads inside a sentence ("The :attribute field is required.", "Add
     * to :label"): first letter lower-cased, except acronyms and in languages that
     * capitalise nouns.
     */
    public static function forSentence(string $label, ?string $locale = null): string
    {
        $language = strtolower(explode('_', str_replace('-', '_', $locale ?? app()->getLocale()))[0]);

        if (in_array($language, self::NOUN_CAPITALISING_LANGUAGES, true)) {
            return $label;
        }

        return self::lowerFirstWord($label);
    }

    /** Lower-case the first letter unless the first word is an acronym ("SEO page"). */
    private static function lowerFirstWord(string $label): string
    {
        $first = explode(' ', $label)[0];

        if (mb_strlen($first) > 1 && $first === mb_strtoupper($first)) {
            return $label;
        }

        return Str::lcfirst($label);
    }

    private static function inflector(string $locale): ?Inflector
    {
        $language = self::INFLECTOR_LANGUAGES[strtolower(explode('_', str_replace('-', '_', $locale))[0])] ?? null;

        if ($language === null) {
            return null;
        }

        return self::$inflectors[$language] ??= InflectorFactory::createForLanguage($language)->build();
    }
}
