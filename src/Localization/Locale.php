<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use Carbon\CarbonImmutable;
use IntlCalendar;
use Locale as IntlLocale;
use Throwable;

/**
 * A language a panel can be displayed in.
 *
 * Only the code is required: the native name, English name, writing direction and
 * first day of the week are derived from ICU/CLDR data and can be overridden.
 *
 * ```php
 * Locale::make('pt_BR');
 * Locale::make('ar')->label('العربية (البحرين)');
 * Locale::make('ku_Arab')->direction(TextDirection::Rtl);
 * ```
 */
final class Locale
{
    private ?string $label = null;

    private ?TextDirection $direction = null;

    private ?int $firstDayOfWeek = null;

    /**
     * @param  string  $code  Laravel-style code (`en`, `pt_BR`, `zh_Hant`). Hyphens are accepted.
     */
    public function __construct(private readonly string $code) {}

    public static function make(string $code): self
    {
        return new self(self::normalize($code));
    }

    /**
     * `pt-br` → `pt_BR`, `zh-hant-tw` → `zh_Hant_TW`.
     */
    public static function normalize(string $code): string
    {
        $parts = explode('_', str_replace('-', '_', trim($code)));
        $normalized = [strtolower(array_shift($parts))];

        foreach ($parts as $part) {
            $normalized[] = match (true) {
                strlen($part) === 4 && ctype_alpha($part) => ucfirst(strtolower($part)),
                strlen($part) <= 3 => strtoupper($part),
                default => $part,
            };
        }

        return implode('_', $normalized);
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function direction(TextDirection|string|null $direction): self
    {
        $this->direction = is_string($direction) ? TextDirection::from($direction) : $direction;

        return $this;
    }

    /**
     * 0 = Sunday … 6 = Saturday.
     */
    public function firstDayOfWeek(?int $day): self
    {
        $this->firstDayOfWeek = $day;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /** The primary language subtag (`pt` for `pt_BR`). */
    public function getLanguage(): string
    {
        return explode('_', $this->code)[0];
    }

    /** BCP 47 tag for `Intl` APIs and the `lang` attribute (`pt-BR`). */
    public function getTag(): string
    {
        return str_replace('_', '-', $this->code);
    }

    /** The language's own name for itself (`Deutsch`, `العربية`). */
    public function getLabel(): string
    {
        return $this->label ?? self::displayName($this->code, $this->code);
    }

    /** The name in another language, English by default. */
    public function getLabelIn(string $locale = 'en'): string
    {
        return self::displayName($this->code, $locale);
    }

    public function getDirection(): TextDirection
    {
        return $this->direction ??= TextDirection::detect($this->code);
    }

    public function getFirstDayOfWeek(): int
    {
        if ($this->firstDayOfWeek !== null) {
            return $this->firstDayOfWeek;
        }

        // ICU resolves the likely region itself (`he` → Israel → Sunday, `ar` → Saturday).
        if (class_exists(IntlCalendar::class)) {
            $day = IntlCalendar::createInstance(null, $this->code)->getFirstDayOfWeek();

            if (is_int($day)) {
                return $this->firstDayOfWeek = $day - 1;
            }
        }

        try {
            $day = CarbonImmutable::now()->locale($this->code)->getTranslationMessage('first_day_of_week');
        } catch (Throwable) {
            $day = null;
        }

        return $this->firstDayOfWeek = is_numeric($day) ? (int) $day : 1;
    }

    /**
     * @return array{code: string, tag: string, label: string, direction: string}
     */
    public function toSchema(): array
    {
        return [
            'code' => $this->code,
            'tag' => $this->getTag(),
            'label' => $this->getLabel(),
            'direction' => $this->getDirection()->value,
        ];
    }

    private static function displayName(string $code, string $inLocale): string
    {
        if (! class_exists(IntlLocale::class)) {
            return $code;
        }

        $name = IntlLocale::getDisplayName($code, $inLocale);

        if (! is_string($name) || $name === $code) {
            return $code;
        }

        // ICU returns lower-case names for some languages ("français"); menus read better capitalised.
        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
