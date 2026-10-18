<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Localization\TranslatableLocale;
use ArrayObject;
use Closure;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use JsonException;

/**
 * `->translatable()`: one value per language, edited side by side and stored as a JSON
 * object (`{"en": "Hello", "ar": "مرحبا"}`). The languages default to the panel's
 * locales. Works with a JSON/array-cast column, a plain text column (JSON-encoded on
 * save) and spatie/laravel-translatable's `HasTranslations` (when installed), whose
 * `getTranslations()` loads every language.
 *
 * `required()` applies to the required locales: the panel's default locale unless
 * `requiredLocales()` says otherwise. Every other rule applies to each language.
 */
trait CanBeTranslatable
{
    protected bool|Closure $isTranslatable = false;

    /** @var array<int|string, string|Locale>|Closure|null */
    protected array|Closure|null $translatableLocales = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $requiredLocales = null;

    /**
     * @param  array<int|string, string|Locale>|Closure|null  $locales  Codes, `code => label` pairs or Locales; the panel's locales by default
     */
    public function translatable(bool|Closure $condition = true, array|Closure|null $locales = null): static
    {
        $this->isTranslatable = $condition;

        if ($locales !== null) {
            $this->translatableLocales = $locales;
        }

        return $this;
    }

    /**
     * The languages that must be filled when the field is `required()`.
     *
     * @param  array<int, string>|Closure|null  $locales
     */
    public function requiredLocales(array|Closure|null $locales): static
    {
        $this->requiredLocales = $locales;

        return $this;
    }

    public function isTranslatable(): bool
    {
        return (bool) $this->evaluate($this->isTranslatable);
    }

    /**
     * @return array<string, Locale> keyed by code
     */
    public function getTranslatableLocales(): array
    {
        $configured = $this->evaluate($this->translatableLocales);

        if (! is_array($configured) || $configured === []) {
            return alfakit()->getCurrentOrDefaultPanel()->getLocales();
        }

        $locales = [];

        foreach ($configured as $key => $value) {
            $locale = match (true) {
                $value instanceof Locale => $value,
                is_string($key) => Locale::make($key)->label((string) $value),
                default => Locale::make((string) $value),
            };

            $locales[$locale->getCode()] = $locale;
        }

        return $locales;
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredLocales(): array
    {
        if (! $this->isRequired()) {
            return [];
        }

        $locales = $this->getTranslatableLocales();
        $required = $this->evaluate($this->requiredLocales);

        if (is_array($required)) {
            return array_values(array_filter(array_map(Locale::normalize(...), $required), static fn (string $code): bool => isset($locales[$code])));
        }

        $default = alfakit()->getCurrentOrDefaultPanel()->getDefaultLocale()->getCode();

        return [isset($locales[$default]) ? $default : (string) array_key_first($locales)];
    }

    // ---------------------------------------------------------------- Schema

    public function toSchema(): array
    {
        $schema = parent::toSchema();

        if (! $this->isTranslatable()) {
            return $schema;
        }

        $required = $this->getRequiredLocales();

        // `defaultLocale` is the language the field opens on: the one chosen with a
        // `LocaleSwitcher` (kept in the session) when the field offers it, else the panel's.
        $schema['translatable'] = [
            'locales' => array_values(array_map(static fn (Locale $locale): array => [
                ...$locale->toSchema(),
                'required' => in_array($locale->getCode(), $required, true) ?: null,
            ], $this->getTranslatableLocales())),
            'defaultLocale' => $this->getEditingTranslatableLocale(),
        ];

        // Each language's input marks itself as required; the field as a whole doesn't.
        unset($schema['required']);

        return $schema;
    }

    // ---------------------------------------------------------------- State

    public function hydrateState(bool $useDefaults): void
    {
        if (! $this->isTranslatable()) {
            parent::hydrateState($useDefaults);

            return;
        }

        $root = $this->getContainer()->getRootSchema();
        $path = $this->getStatePath();

        if (! $useDefaults && Arr::has($root->getRawState(), $path)) {
            $root->setStateAt($path, $this->normalizeTranslations($this->getStoredTranslations() ?? $this->getState()));
        }

        parent::hydrateState($useDefaults);

        $root->setStateAt($path, $this->normalizeTranslations($this->getState()));
    }

    public function dehydrateState(): mixed
    {
        if (! $this->isTranslatable()) {
            return parent::dehydrateState();
        }

        $state = $this->normalizeTranslations($this->getState());

        if ($this->dehydrateStateUsing !== null) {
            $state = (array) $this->evaluate($this->dehydrateStateUsing, ['state' => $state]);
        }

        $isSpatie = $this->usesSpatieTranslations();
        $translations = [];

        foreach (array_keys($this->getTranslatableLocales()) as $code) {
            $value = $this->mutateDehydratedState($state[$code] ?? null);

            // Spatie only overwrites the languages it is given, so emptied ones are sent too.
            if ($isSpatie || ($value !== null && $value !== '' && $value !== [])) {
                $translations[$code] = $value;
            }
        }

        // Languages the field doesn't edit (e.g. removed from the panel) keep their stored
        // value. They come from the record, never from the browser.
        foreach ($this->getStoredTranslations() ?? [] as $code => $value) {
            if (! array_key_exists($code, $this->getTranslatableLocales()) && ! array_key_exists($code, $translations)) {
                $translations[$code] = $value;
            }
        }

        if ($this->mutateDehydratedStateUsing !== null) {
            $translations = $this->evaluate($this->mutateDehydratedStateUsing, ['state' => $translations]);
        }

        if (! is_array($translations) || ! $this->storesTranslationsAsJsonString()) {
            return $translations === [] && ! $isSpatie ? null : $translations;
        }

        if ($translations === []) {
            return null;
        }

        try {
            return json_encode($translations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * A live update of one language (`title.en`) runs this field's `afterStateUpdated`.
     */
    public function managesChildFieldState(): bool
    {
        return $this->isTranslatable() || parent::managesChildFieldState();
    }

    public function getNestedFieldByStatePath(string $path): ?Field
    {
        if ($this->isTranslatable() && str_starts_with($path, $this->getStatePath().'.')) {
            return $this;
        }

        return parent::getNestedFieldByStatePath($path);
    }

    // ---------------------------------------------------------------- Validation

    public function getValidationRulesByPath(): array
    {
        if (! $this->isTranslatable()) {
            return parent::getValidationRulesByPath();
        }

        $path = $this->getStatePath();
        $rules = array_values(array_filter($this->getValidationRules(), static fn (mixed $rule): bool => $rule !== 'required' && $rule !== 'nullable'));
        $required = $this->getRequiredLocales();
        $byPath = [$path => ['nullable', 'array']];

        foreach (array_keys($this->getTranslatableLocales()) as $code) {
            $byPath["{$path}.{$code}"] = [in_array($code, $required, true) ? 'required' : 'nullable', ...$rules];
        }

        return $byPath;
    }

    public function getValidationAttributesByPath(): array
    {
        if (! $this->isTranslatable()) {
            return parent::getValidationAttributesByPath();
        }

        $path = $this->getStatePath();
        $attribute = $this->getValidationAttribute();
        $attributes = [$path => $attribute];

        foreach ($this->getTranslatableLocales() as $code => $locale) {
            $attributes["{$path}.{$code}"] = __('alfakit::forms.translatable.attribute', ['attribute' => $attribute, 'locale' => $locale->getLabel()]);
        }

        return $attributes;
    }

    public function getValidationMessagesByPath(): array
    {
        if (! $this->isTranslatable()) {
            return parent::getValidationMessagesByPath();
        }

        $messages = [];

        foreach ($this->getValidationMessages() as $rule => $message) {
            foreach (array_keys($this->getTranslatableLocales()) as $code) {
                $messages["{$this->getStatePath()}.{$code}.{$rule}"] = $message;
            }
        }

        return $messages;
    }

    // ---------------------------------------------------------------- Storage

    /**
     * `code => value` for every editable language (missing ones are null), plus any
     * other language in the value. A plain string is the default language's value.
     *
     * @return array<string, mixed>
     */
    protected function normalizeTranslations(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) && ! array_is_list($decoded) ? $decoded : ($value === '' ? [] : [$this->getDefaultTranslatableLocale() => $value]);
        } elseif ($value instanceof ArrayObject || $value instanceof \Illuminate\Support\Collection) {
            $value = $value instanceof ArrayObject ? $value->getArrayCopy() : $value->all();
        }

        $value = is_array($value) ? $value : [];
        $translations = [];

        foreach (array_keys($this->getTranslatableLocales()) as $code) {
            $translations[$code] = $value[$code] ?? null;
        }

        foreach ($value as $code => $translation) {
            if (is_string($code) && ! array_key_exists($code, $translations) && $code !== '') {
                $translations[$code] = $translation;
            }
        }

        return $translations;
    }

    protected function getDefaultTranslatableLocale(): string
    {
        $locales = $this->getTranslatableLocales();
        $default = alfakit()->getCurrentOrDefaultPanel()->getDefaultLocale()->getCode();

        return isset($locales[$default]) ? $default : (string) array_key_first($locales);
    }

    /**
     * The language the field opens on: the user's editing language (`LocaleSwitcher`) when
     * the field offers it, else the default language.
     */
    protected function getEditingTranslatableLocale(): string
    {
        $editing = TranslatableLocale::get();

        if ($editing !== null && isset($this->getTranslatableLocales()[$editing])) {
            return $editing;
        }

        return $this->getDefaultTranslatableLocale();
    }

    /**
     * Every language stored on the record, when the field maps to one of its attributes.
     *
     * @return array<string, mixed>|null
     */
    protected function getStoredTranslations(): ?array
    {
        $record = $this->getRecord();
        $attribute = $this->getStatePath(isAbsolute: false);

        if (! $record instanceof Model || str_contains($attribute, '.') || $attribute !== $this->getStatePath()) {
            return null;
        }

        if ($this->usesSpatieTranslations() && method_exists($record, 'getTranslations')) {
            return (array) $record->getTranslations($attribute);
        }

        $value = $record->getAttribute($attribute);

        return $value === null ? null : array_filter($this->normalizeTranslations($value), static fn (mixed $translation): bool => $translation !== null);
    }

    /**
     * Whether the model attribute uses spatie/laravel-translatable's `HasTranslations`.
     */
    protected function usesSpatieTranslations(): bool
    {
        $model = $this->getModelInstance();
        $attribute = $this->getStatePath(isAbsolute: false);

        return $model !== null
            && method_exists($model, 'isTranslatableAttribute')
            && $model->isTranslatableAttribute($attribute);
    }

    /**
     * A top-level attribute without an array/JSON cast (or spatie) is stored as a JSON
     * string; nested paths and cast attributes take the array.
     */
    protected function storesTranslationsAsJsonString(): bool
    {
        $model = $this->getModelInstance();
        $attribute = $this->getStatePath(isAbsolute: false);

        if ($model === null || str_contains($attribute, '.') || $attribute !== $this->getStatePath() || $this->usesSpatieTranslations()) {
            return false;
        }

        // Eloquent compares cast types in lower case, class-based casts included.
        return ! $model->hasCast($attribute, array_map(strtolower(...), [
            'array', 'json', 'json:unicode', 'object', 'collection',
            'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection',
            AsArrayObject::class, AsCollection::class,
        ]));
    }
}
