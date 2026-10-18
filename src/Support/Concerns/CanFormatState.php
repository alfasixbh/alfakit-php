<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\NumberFormat;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Display formatting shared by table text columns and infolist text entries.
 */
trait CanFormatState
{
    protected ?Closure $formatStateUsing = null;

    protected string|Closure|null $dateFormat = null;

    protected string|Closure|null $timezone = null;

    /** @var array{0: array<string, mixed>, 1: array<class-string, object>} Injections of the value being formatted. */
    protected array $formatStateInjections = [[], []];

    protected bool|Closure $isSince = false;

    protected bool $isMoney = false;

    protected string|Closure|null $currency = null;

    protected bool|Closure $isInMinorUnits = false;

    protected int|Closure|null $divideBy = null;

    protected int|Closure|null $decimalPlaces = null;

    protected string|Closure|null $decimalSeparator = null;

    protected string|Closure|null $thousandsSeparator = null;

    protected int|Closure|null $maxDecimalPlaces = null;

    protected int|Closure|null $moneyDecimalPlaces = null;

    protected string|BackedEnum|Closure|null $locale = null;

    protected bool|Closure $isNumeric = false;

    protected int|Closure|null $characterLimit = null;

    protected string|Closure $characterLimitEnd = '…';

    protected int|Closure|null $wordLimit = null;

    protected string|Closure|null $prefix = null;

    protected string|Closure|null $suffix = null;

    protected bool|Closure $isMarkdown = false;

    protected bool|Closure $isHtml = false;

    protected bool|Closure $shouldSanitizeHtml = true;

    protected bool|Closure $isListWithLineBreaks = false;

    protected bool|Closure $isBulleted = false;

    protected string|Closure|null $separator = null;

    protected int|Closure|null $listLimit = null;

    protected string|Closure|null $placeholder = null;

    protected bool|Closure $isBoolean = false;

    public function formatStateUsing(?Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function date(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $this->dateFormat = $format ?? 'M j, Y';

        if ($timezone !== null) {
            $this->timezone = $timezone;
        }

        return $this;
    }

    /**
     * The timezone dates are shown in (default: the panel's, `alfakit()->getTimezone()`).
     * Applies to `date()`, `dateTime()`, `time()`, `since()` and the iso formats, in any
     * order: `->dateTime()->timezone('Asia/Dubai')`.
     */
    public function timezone(string|Closure|null $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function dateTime(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->date($format ?? 'M j, Y H:i', $timezone);
    }

    public function time(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->date($format ?? 'H:i', $timezone);
    }

    public function since(string|Closure|null $timezone = null): static
    {
        $this->isSince = true;

        if ($timezone !== null) {
            $this->timezone = $timezone;
        }

        return $this;
    }

    /**
     * Format as money in the user's locale. The currency defaults to the panel's and may
     * be a closure (`fn (Order $record) => $record->currency`) for per-row currencies.
     * Pass `inMinorUnits: true` when amounts are stored as integers of the smallest
     * unit (cents, fils, yen): the divisor follows the currency (100, 1000, 1).
     * `decimalPlaces` overrides the currency's precision (`decimalPlaces: 0` → "$1,235").
     * An integer as the fourth positional argument is read as `decimalPlaces` (Filament's
     * order).
     */
    public function money(string|Closure|null $currency = null, int|Closure|null $divideBy = null, string|BackedEnum|Closure|null $locale = null, bool|int|Closure $inMinorUnits = false, int|Closure|null $decimalPlaces = null): static
    {
        if (is_int($inMinorUnits)) {
            $decimalPlaces ??= $inMinorUnits;
            $inMinorUnits = false;
        }

        $this->isMoney = true;
        $this->currency = $currency;
        $this->divideBy = $divideBy;
        $this->locale = $locale;
        $this->isInMinorUnits = $inMinorUnits;
        $this->moneyDecimalPlaces = $decimalPlaces;

        return $this;
    }

    public function getCurrency(): string
    {
        return Currency::normalize((string) ($this->evaluate($this->currency) ?? alfakit()->getCurrency()));
    }

    /**
     * Format as a number in the user's locale. `decimalPlaces` fixes the decimals;
     * `maxDecimalPlaces` rounds to at most that many and drops trailing zeros. Pass
     * `decimalSeparator` / `thousandsSeparator` to use fixed separators instead of the
     * locale's. A locale code as the second positional argument (`numeric(2, 'de')`, the
     * AlfaKit 1.x order) is read as `locale`.
     */
    public function numeric(int|Closure|null $decimalPlaces = null, string|Closure|null $decimalSeparator = null, string|Closure|null $thousandsSeparator = null, int|Closure|null $maxDecimalPlaces = null, string|BackedEnum|Closure|null $locale = null): static
    {
        if (is_string($decimalSeparator) && $locale === null && preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $decimalSeparator) === 1) {
            $locale = $decimalSeparator;
            $decimalSeparator = null;
        }

        $this->isNumeric = true;
        $this->decimalPlaces = $decimalPlaces;
        $this->decimalSeparator = $decimalSeparator;
        $this->thousandsSeparator = $thousandsSeparator;
        $this->maxDecimalPlaces = $maxDecimalPlaces;
        $this->locale = $locale;

        return $this;
    }

    public function limit(int|Closure|null $length = 100, string|Closure $end = '…'): static
    {
        $this->characterLimit = $length;
        $this->characterLimitEnd = $end;

        return $this;
    }

    public function words(int|Closure|null $words = 100, string|Closure $end = '…'): static
    {
        $this->wordLimit = $words;
        $this->characterLimitEnd = $end;

        return $this;
    }

    public function prefix(string|Closure|null $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string|Closure|null $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Render the state as Markdown. Raw HTML in it is escaped and the result is sanitised.
     */
    public function markdown(bool|Closure $condition = true): static
    {
        $this->isMarkdown = $condition;

        return $this;
    }

    /**
     * Render the state as HTML. String state is sanitised (scripts, event handlers and
     * unsafe URLs removed, see `Html::sanitize()`); an `Htmlable` returned from
     * `formatStateUsing()` is markup written in code and is sent as-is. Pass
     * `sanitize: false` only for trusted content.
     */
    public function html(bool|Closure $condition = true, bool|Closure $sanitize = true): static
    {
        $this->isHtml = $condition;
        $this->shouldSanitizeHtml = $sanitize;

        return $this;
    }

    public function listWithLineBreaks(bool|Closure $condition = true): static
    {
        $this->isListWithLineBreaks = $condition;

        return $this;
    }

    public function bulleted(bool|Closure $condition = true): static
    {
        $this->isBulleted = $condition;

        return $this->listWithLineBreaks($condition);
    }

    public function separator(string|Closure|null $separator = ','): static
    {
        $this->separator = $separator;

        return $this;
    }

    public function limitList(int|Closure|null $limit = 3): static
    {
        $this->listLimit = $limit;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function boolean(bool|Closure $condition = true): static
    {
        $this->isBoolean = $condition;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->evaluate($this->placeholder);
    }

    /**
     * Format a single (non-array) value for display. For an item of a relationship list
     * (`tags.name`), `$relatedRecord` is the model it was read from: closures such as
     * `formatStateUsing()`, `limit()`, `words()`, `prefix()` / `suffix()` and `timezone()`
     * receive it as `$relatedRecord` (or by its class).
     */
    public function formatState(mixed $state, ?Model $relatedRecord = null): mixed
    {
        $previous = $this->formatStateInjections;
        $this->formatStateInjections = $relatedRecord !== null
            ? [['relatedRecord' => $relatedRecord], [$relatedRecord::class => $relatedRecord]]
            : [[], []];

        try {
            return $this->formatStateWithInjections($state);
        } finally {
            $this->formatStateInjections = $previous;
        }
    }

    protected function formatStateWithInjections(mixed $state): mixed
    {
        // A stored backing value becomes its case when the component names the enum.
        if (method_exists($this, 'resolveEnumCase')) {
            $state = $this->resolveEnumCase($state) ?? $state;
        }

        if ($this->formatStateUsing !== null) {
            $state = $this->evaluateFormatOption($this->formatStateUsing, $state);
        }

        // Enum cases show their label (`HasLabel::getLabel()`, or the headlined case name).
        if ($state instanceof UnitEnum) {
            $state = EnumState::label($state);
        }

        if ($state === null || $state === '') {
            return null;
        }

        $isMarkdown = (bool) $this->evaluateFormatOption($this->isMarkdown, $state);
        $isHtml = ! $isMarkdown && (bool) $this->evaluateFormatOption($this->isHtml, $state);

        // Markup built in code (`new HtmlString(...)`, a view) is trusted.
        if ($isHtml && $state instanceof Htmlable) {
            return $state->toHtml();
        }

        if ($this->evaluateFormatOption($this->isBoolean, $state)) {
            return (bool) $state;
        }

        if ($this->isSince || $this->dateFormat !== null) {
            $state = $this->formatDate($state);
        }

        if ($this->isMoney && is_numeric($state)) {
            $currency = $this->getCurrency();
            $locale = $this->getFormatLocale();

            $moneyDecimals = $this->evaluate($this->moneyDecimalPlaces);

            if ($moneyDecimals !== null) {
                $amount = $this->evaluate($this->isInMinorUnits)
                    ? (float) Currency::fromMinorUnits((int) $state, $currency)
                    : (float) $state;
                $divideBy = $this->evaluate($this->isInMinorUnits) ? null : $this->evaluate($this->divideBy);
                $amount = $divideBy ? $amount / $divideBy : $amount;

                $state = NumberFormat::currency($amount, $currency, $locale ?? app()->getLocale(), max(0, (int) $moneyDecimals)) ?: Currency::format($amount, $currency, $locale);
            } elseif ($this->evaluate($this->isInMinorUnits)) {
                $state = Currency::format((int) $state, $currency, $locale, inMinorUnits: true);
            } else {
                $amount = (float) $state;
                $divideBy = $this->evaluate($this->divideBy);

                $state = Currency::format($divideBy ? $amount / $divideBy : $amount, $currency, $locale);
            }
        } elseif ($this->evaluate($this->isNumeric) && is_numeric($state)) {
            $state = $this->formatNumber((float) $state);
        }

        if (is_scalar($state) || $state instanceof Stringable) {
            $state = (string) $state;

            if (($limit = $this->evaluateFormatOption($this->characterLimit, $state)) !== null) {
                $state = Str::limit($state, $limit, $this->evaluateFormatOption($this->characterLimitEnd, $state));
            }

            if (($words = $this->evaluateFormatOption($this->wordLimit, $state)) !== null) {
                $state = Str::words($state, $words, $this->evaluateFormatOption($this->characterLimitEnd, $state));
            }

            $sanitize = (bool) $this->evaluateFormatOption($this->shouldSanitizeHtml, $state);

            if ($isMarkdown) {
                $state = Html::markdown($state, $sanitize);
            }

            $prefix = $this->evaluateFormatOption($this->prefix, $state);
            $suffix = $this->evaluateFormatOption($this->suffix, $state);

            // Markdown output is HTML, so plain prefixes and suffixes must be escaped.
            if ($isMarkdown) {
                $prefix = Html::from($prefix);
                $suffix = Html::from($suffix);
            }

            if ($prefix !== null || $suffix !== null) {
                $state = ($prefix ?? '').$state.($suffix ?? '');
            }

            // State is data (a record attribute, user input): never trust it as markup.
            if ($isHtml && $sanitize) {
                $state = Html::sanitize($state);
            }
        }

        return $state;
    }

    protected function formatNumber(float $number): string
    {
        $decimals = $this->evaluate($this->decimalPlaces);
        $maxDecimals = $this->evaluate($this->maxDecimalPlaces);
        $decimalSeparator = $this->evaluate($this->decimalSeparator);
        $thousandsSeparator = $this->evaluate($this->thousandsSeparator);

        if ($decimalSeparator !== null || $thousandsSeparator !== null) {
            $places = $decimals ?? $maxDecimals ?? 0;
            $formatted = number_format($number, (int) $places, $decimalSeparator ?? '.', $thousandsSeparator ?? ',');

            // `maxDecimalPlaces` drops trailing zeros (and a dangling separator).
            if ($decimals === null && $maxDecimals !== null && $places > 0) {
                $separator = $decimalSeparator ?? '.';
                $formatted = rtrim(rtrim($formatted, '0'), $separator);
            }

            return $formatted;
        }

        if ($decimals === null && $maxDecimals !== null) {
            return NumberFormat::format($number, maxPrecision: (int) $maxDecimals, locale: $this->getFormatLocale()) ?: (string) $number;
        }

        // `decimalPlaces` fixes the decimals (`numeric(2)`: 1,234.50), like Filament.
        return NumberFormat::format($number, $decimals, $maxDecimals, $this->getFormatLocale()) ?: (string) $number;
    }

    protected function formatDate(mixed $state): mixed
    {
        try {
            $date = self::toCarbon($state);
        } catch (Throwable) {
            return $state;
        }

        $date = $date->setTimezone($this->evaluateFormatOption($this->timezone, $state) ?? $this->getDisplayTimezone());

        if ($this->evaluate($this->isSince)) {
            return $date->diffForHumans();
        }

        return $date->translatedFormat($this->evaluate($this->dateFormat));
    }

    /**
     * A Carbon to format: a copy of a (mutable, Laravel) Carbon state, which is far cheaper
     * than `Carbon::instance()` re-parsing it, else a new instance.
     */
    protected static function toCarbon(mixed $state): Carbon
    {
        if ($state instanceof Carbon) {
            return clone $state;
        }

        return $state instanceof DateTimeInterface ? Carbon::instance($state) : Carbon::parse((string) $state);
    }

    /**
     * The timezone dates are shown in without `timezone()`: the panel's.
     */
    protected function getDisplayTimezone(): string
    {
        return alfakit()->getTimezone();
    }

    /**
     * Format a value that may be a list (JSON cast, collection, separator string).
     *
     * @return mixed|array<mixed>
     */
    public function formatStateForDisplay(mixed $state): mixed
    {
        if ($state instanceof \Illuminate\Support\Collection) {
            $state = $state->all();
        }

        $separator = $this->evaluate($this->separator);

        if ($separator !== null && is_string($state)) {
            $state = array_map('trim', explode($separator, $state));
        }

        if (is_array($state)) {
            $state = array_values(array_filter($state, static fn (mixed $item): bool => $item !== null && $item !== ''));
            $relatedRecords = $this->getRelatedRecordsForListItems(count($state));
            $items = [];

            foreach ($state as $index => $item) {
                $items[] = $this->formatState($item, $relatedRecords[$index] ?? null);
            }

            if (($limit = $this->evaluate($this->listLimit)) !== null && count($items) > $limit) {
                $remaining = count($items) - $limit;
                $items = [...array_slice($items, 0, $limit), __('alfakit::tables.list.more', ['count' => $remaining])];
            }

            return $items;
        }

        return $this->formatState($state);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getFormattingSchemaProps(): array
    {
        return [
            'html' => ($this->evaluate($this->isHtml) || $this->evaluate($this->isMarkdown)) ?: null,
            'listWithLineBreaks' => $this->evaluate($this->isListWithLineBreaks) ?: null,
            'bulleted' => $this->evaluate($this->isBulleted) ?: null,
            'placeholder' => $this->getPlaceholder(),
            'boolean' => $this->evaluate($this->isBoolean) ?: null,
        ];
    }

    /**
     * The `numeric()` / `money()` locale: a code, or a backed enum whose value is one
     * (`Locale::De`). Null uses the app's locale.
     */
    protected function getFormatLocale(): ?string
    {
        $locale = $this->evaluate($this->locale);

        if ($locale instanceof BackedEnum) {
            $locale = (string) $locale->value;
        }

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * The model each item of a relationship list state was read from (aligned with the
     * items), or `[]` when the component does not read through a relationship.
     *
     * @return array<int, Model|null>
     */
    protected function getRelatedRecordsForListItems(int $count): array
    {
        if (! method_exists($this, 'getRelatedRecordItems')) {
            return [];
        }

        $items = $this->getRelatedRecordItems();

        return is_array($items) && count($items) === $count ? array_column($items, 1) : [];
    }

    /**
     * Evaluate a formatting option for one value: `$state` is the value, and while an item
     * of a relationship list is formatted `$relatedRecord` is its model.
     */
    protected function evaluateFormatOption(mixed $value, mixed $state = null): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        [$named, $typed] = $this->formatStateInjections;

        return $this->evaluate($value, ['state' => $state, ...$named], $typed);
    }
}
