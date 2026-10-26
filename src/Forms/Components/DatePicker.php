<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\HasAffixes;
use AlfaSix\Alfakit\Localization\Locale;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

class DatePicker extends Field
{
    use HasAffixes;

    protected string|Closure|null $format = null;

    protected string|Closure|null $displayFormat = null;

    protected string|DateTimeInterface|Closure|null $minDate = null;

    protected string|DateTimeInterface|Closure|null $maxDate = null;

    /** @var array<string>|Closure */
    protected array|Closure $disabledDates = [];

    protected bool|Closure $isNative = true;

    protected string|Closure|null $timezone = null;

    /** Null: the panel locale decides (Monday in most of Europe, Sunday in the US…). */
    protected int|Closure|null $firstDayOfWeek = null;

    protected int|Closure|null $hoursStep = null;

    protected int|Closure|null $minutesStep = null;

    protected int|Closure|null $secondsStep = null;

    protected string|DateTimeInterface|Closure|null $defaultFocusedDate = null;

    protected bool|Closure $closeOnDateSelection = true;

    protected bool|Closure $hasTime = false;

    protected bool|Closure $hasDate = true;

    protected bool|Closure $hasSeconds = false;

    /** Null: the panel's locale. */
    protected string|Closure|null $locale = null;

    /** @var array<string|DateTimeInterface>|Closure|null */
    protected array|Closure|null $datalist = null;

    protected function setUp(): void
    {
        parent::setUp();

        // A time of day has no year, month or day, which Laravel's `date` rule requires.
        $this->rule('date', fn (): bool => $this->hasDate());
    }

    /**
     * The PHP date format used for stored values. Defaults to `Y-m-d` (or `Y-m-d H:i:s`).
     */
    public function format(string|Closure|null $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function displayFormat(string|Closure|null $format): static
    {
        $this->displayFormat = $format;

        return $this;
    }

    public function minDate(string|DateTimeInterface|Closure|null $date): static
    {
        $this->minDate = $date;

        return $this;
    }

    public function maxDate(string|DateTimeInterface|Closure|null $date): static
    {
        $this->maxDate = $date;

        return $this;
    }

    /**
     * @param  array<string>|Closure  $dates
     */
    public function disabledDates(array|Closure $dates): static
    {
        $this->disabledDates = $dates;

        return $this;
    }

    public function native(bool|Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    public function timezone(string|Closure|null $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * 0 (Sunday) to 6 (Saturday); null follows the panel locale.
     */
    public function firstDayOfWeek(int|Closure|null $day): static
    {
        $this->firstDayOfWeek = $day;

        return $this;
    }

    /**
     * Language of this picker's month and weekday names, its display format and
     * its default first day of the week (e.g. `'fr'`), instead of the panel's.
     */
    public function locale(string|Closure|null $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): ?string
    {
        $locale = $this->evaluate($this->locale);

        return is_string($locale) && $locale !== '' ? str_replace('_', '-', $locale) : null;
    }

    /**
     * Suggested values (in the storage format, or date objects) offered by the
     * browser's native picker. Only native pickers (`native()`, the default, and
     * `TimePicker`) can show them.
     *
     * @param  array<string|DateTimeInterface>|Closure|null  $options
     */
    public function datalist(array|Closure|null $options): static
    {
        $this->datalist = $options;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getDatalist(): ?array
    {
        $options = $this->evaluate($this->datalist);

        if (! is_array($options) || $options === []) {
            return null;
        }

        return array_values(array_map(fn (mixed $option): string => (string) $this->formatBoundForDisplay($option), $options));
    }

    public function weekStartsOnMonday(): static
    {
        return $this->firstDayOfWeek(1);
    }

    public function weekStartsOnSunday(): static
    {
        return $this->firstDayOfWeek(0);
    }

    public function getFirstDayOfWeek(): int
    {
        $day = $this->evaluate($this->firstDayOfWeek);

        if ($day !== null) {
            return (int) $day % 7;
        }

        $locale = $this->getLocale();

        return $locale === null ? alfakit()->getCurrentLocale()->getFirstDayOfWeek() : Locale::make($locale)->getFirstDayOfWeek();
    }

    /**
     * Step of the hours in the time picker (e.g. `2` offers 00, 02, 04…).
     */
    public function hoursStep(int|Closure|null $step): static
    {
        $this->hoursStep = $step;

        return $this;
    }

    /**
     * Step of the minutes in the time picker (e.g. `15` offers :00, :15, :30, :45).
     */
    public function minutesStep(int|Closure|null $step): static
    {
        $this->minutesStep = $step;

        return $this;
    }

    public function secondsStep(int|Closure|null $step): static
    {
        $this->secondsStep = $step;

        return $this;
    }

    /**
     * The month the calendar opens on while the field is empty (defaults to today).
     */
    public function defaultFocusedDate(string|DateTimeInterface|Closure|null $date): static
    {
        $this->defaultFocusedDate = $date;

        return $this;
    }

    protected function getPositiveStep(int|Closure|null $step): ?int
    {
        $step = $this->evaluate($step);

        return is_numeric($step) && (int) $step > 1 ? (int) $step : null;
    }

    public function closeOnDateSelection(bool|Closure $condition = true): static
    {
        $this->closeOnDateSelection = $condition;

        return $this;
    }

    public function seconds(bool|Closure $condition = true): static
    {
        $this->hasSeconds = $condition;

        return $this;
    }

    public function hasTime(): bool
    {
        return (bool) $this->evaluate($this->hasTime);
    }

    public function hasDate(): bool
    {
        return (bool) $this->evaluate($this->hasDate);
    }

    public function hasSeconds(): bool
    {
        return (bool) $this->evaluate($this->hasSeconds);
    }

    public function getFormat(): string
    {
        $format = $this->evaluate($this->format);

        if ($format !== null) {
            return $format;
        }

        if (! $this->hasDate()) {
            return $this->hasSeconds() ? 'H:i:s' : 'H:i';
        }

        return $this->hasTime() ? ($this->hasSeconds() ? 'Y-m-d H:i:s' : 'Y-m-d H:i') : 'Y-m-d';
    }

    public function getTimezone(): string
    {
        return $this->evaluate($this->timezone) ?? alfakit()->getTimezone();
    }

    /**
     * Stored value (app timezone) → what the user sees and edits (display timezone).
     */
    protected function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value, $this->getStorageTimezone());

        if ($this->shiftsTimezone()) {
            $date = $date->setTimezone($this->getTimezone());
        }

        return $date->format($this->getFormat());
    }

    /**
     * Submitted value (display timezone) → stored value (app timezone).
     */
    protected function denormalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (! $this->shiftsTimezone()) {
            return Carbon::parse((string) $value)->format($this->getFormat());
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value, $this->getTimezone());

        return $date->setTimezone($this->getStorageTimezone())->format($this->getFormat());
    }

    /** Only full date-times are moments in time; dates and times of day are not shifted. */
    protected function shiftsTimezone(): bool
    {
        return $this->hasDate() && $this->hasTime();
    }

    protected function getStorageTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    public function hydrateState(bool $useDefaults): void
    {
        parent::hydrateState($useDefaults);

        $state = $this->getState();

        if ($state instanceof CarbonInterface || (is_string($state) && $state !== '')) {
            try {
                $this->state($this->normalizeDate($state));
            } catch (Throwable) {
                // Leave unparsable input untouched so validation can report it.
            }
        }
    }

    /**
     * A date object written into the state after hydration (`$set('published_at', now())`,
     * `$component->state(…)`) in the format and timezone the picker edits, for the browser;
     * JSON would otherwise send it as UTC ISO-8601, which no picker parses (W-S1-05).
     */
    public function formatStateForFrontend(mixed $state): mixed
    {
        if (! $state instanceof DateTimeInterface) {
            return $state;
        }

        try {
            return $this->normalizeDate($state);
        } catch (Throwable) {
            return $state;
        }
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if (blank($state)) {
            return null;
        }

        try {
            return $this->denormalizeDate($state);
        } catch (Throwable) {
            return $state;
        }
    }

    protected function getTypeValidationRules(): array
    {
        $rules = [];

        if (($min = $this->evaluate($this->minDate)) !== null) {
            $rules[] = 'after_or_equal:'.($min instanceof DateTimeInterface ? $min->format($this->getFormat()) : $min);
        }

        if (($max = $this->evaluate($this->maxDate)) !== null) {
            $rules[] = 'before_or_equal:'.($max instanceof DateTimeInterface ? $max->format($this->getFormat()) : $max);
        }

        return $rules;
    }

    protected function normalizeFocusedDate(mixed $date): mixed
    {
        if ($date === null || $date instanceof DateTimeInterface) {
            return $date;
        }

        try {
            return Carbon::parse((string) $date, $this->shiftsTimezone() ? $this->getStorageTimezone() : null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Min/max dates in the timezone the user edits in, so the calendar compares like with like.
     */
    protected function formatBoundForDisplay(mixed $bound): mixed
    {
        if (! $bound instanceof DateTimeInterface) {
            return $bound;
        }

        $date = Carbon::instance($bound);

        if ($this->shiftsTimezone()) {
            $date = $date->setTimezone($this->getTimezone());
        }

        return $date->format($this->getFormat());
    }

    protected function getSchemaProps(): array
    {
        $min = $this->evaluate($this->minDate);
        $max = $this->evaluate($this->maxDate);

        return [
            'format' => $this->getFormat(),
            'displayFormat' => $this->evaluate($this->displayFormat),
            'hasDate' => $this->hasDate(),
            'hasTime' => $this->hasTime(),
            'hasSeconds' => $this->hasSeconds() ?: null,
            'minDate' => $this->formatBoundForDisplay($min),
            'maxDate' => $this->formatBoundForDisplay($max),
            'disabledDates' => $this->evaluate($this->disabledDates) ?: null,
            'native' => (bool) $this->evaluate($this->isNative),
            'timezone' => $this->getTimezone(),
            'firstDayOfWeek' => $this->getFirstDayOfWeek(),
            'hoursStep' => $this->hasTime() ? $this->getPositiveStep($this->hoursStep) : null,
            'minutesStep' => $this->hasTime() ? $this->getPositiveStep($this->minutesStep) : null,
            'secondsStep' => $this->hasTime() && $this->hasSeconds() ? $this->getPositiveStep($this->secondsStep) : null,
            'defaultFocusedDate' => $this->hasDate() ? $this->formatBoundForDisplay($this->normalizeFocusedDate($this->evaluate($this->defaultFocusedDate))) : null,
            'closeOnDateSelection' => (bool) $this->evaluate($this->closeOnDateSelection),
            'locale' => $this->getLocale(),
            'datalist' => $this->getDatalist(),
            ...$this->getAffixes(),
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
