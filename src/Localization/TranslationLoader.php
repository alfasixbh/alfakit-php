<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use Illuminate\Contracts\Translation\Loader;

/**
 * Decorates Laravel's translation loader so namespaces registered with {@see Translations}
 * are read from every registered directory, then from the app's `lang/vendor` overrides.
 * Everything else is delegated untouched.
 */
final class TranslationLoader implements Loader
{
    public function __construct(
        private readonly Loader $loader,
        private readonly string $overridesPath,
    ) {}

    /**
     * @return array<mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        if (! Translations::isManaged($namespace) || $group === '*') {
            return $this->loader->load($locale, $group, $namespace);
        }

        $lines = [];
        $locale = (string) $locale;

        // A regional or script variant (`ar_EG`, `pt-PT`, `ku_Arab`) starts from its base
        // language's strings, so a variant without a pack of its own isn't shown in English.
        $base = preg_split('/[-_]/', $locale)[0] ?? $locale;
        $locales = $base !== '' && $base !== $locale ? [$base, $locale] : [$locale];

        foreach ([...Translations::paths($namespace), $this->overridesPath.'/'.$namespace] as $directory) {
            foreach ($locales as $candidate) {
                $file = "{$directory}/{$candidate}/{$group}.php";

                if (is_file($file)) {
                    $loaded = require $file;

                    if (is_array($loaded)) {
                        $lines = array_replace_recursive($lines, $loaded);
                    }
                }
            }
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->loader->namespaces();
    }

    /**
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->loader->{$method}(...$arguments);
    }
}
