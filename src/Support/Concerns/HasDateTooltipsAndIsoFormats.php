<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Extras of `TextColumn` and `TextEntry` on top of `CanFormatState`: Carbon `isoFormat()`
 * dates, date tooltips, the character limit getter and expandable lists. Dates are
 * formatted in PHP in the user's locale and the panel timezone, like `date()`.
 *
 * Used next to `CanFormatState` with
 * `HasDateTooltipsAndIsoFormats::formatDate insteadof CanFormatState` and
 * `CanFormatState::formatDate as formatDateWithPhpFormat`.
 */
trait HasDateTooltipsAndIsoFormats
{
    /** The `dateFormat` set by `isoDate()` & co., formatted with `isoFormat()`. */
    protected string|Closure|null $isoDateFormat = null;

    /** @var array{type: string, format: string|Closure|null, timezone: string|Closure|null}|null */
    protected ?array $dateTooltip = null;

    protected bool|Closure $isLimitedListExpandable = false;

    /**
     * Format as a date with a Carbon `isoFormat()` pattern (`'L'`, `'ll'`, `'dddd D MMMM'`).
     */
    public function isoDate(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $format ??= 'L';
        $this->date($format, $timezone);
        $this->isoDateFormat = $format;

        return $this;
    }

    public function isoDateTime(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->isoDate($format ?? 'LLL', $timezone);
    }

    public function isoTime(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->isoDate($format ?? 'LT', $timezone);
    }

    public function dateTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('date', $format ?? 'M j, Y', $timezone);
    }

    public function dateTimeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('date', $format ?? 'M j, Y H:i', $timezone);
    }

    public function timeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('date', $format ?? 'H:i', $timezone);
    }

    public function isoDateTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('iso', $format ?? 'L', $timezone);
    }

    public function isoDateTimeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('iso', $format ?? 'LLL', $timezone);
    }

    public function isoTimeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('iso', $format ?? 'LT', $timezone);
    }

    /**
     * A "3 days ago" tooltip.
     */
    public function sinceTooltip(string|Closure|null $timezone = null): static
    {
        return $this->setDateTooltip('since', null, $timezone);
    }

    protected function setDateTooltip(string $type, string|Closure|null $format, string|Closure|null $timezone): static
    {
        $this->dateTooltip = ['type' => $type, 'format' => $format, 'timezone' => $timezone];

        return $this;
    }

    /**
     * The date tooltip for the current (single, date-like) state, or null.
     */
    public function getDateTooltip(): ?string
    {
        if ($this->dateTooltip === null) {
            return null;
        }

        $state = $this->getState();

        if (! is_string($state) && ! is_int($state) && ! $state instanceof DateTimeInterface) {
            return null;
        }

        try {
            $date = $state instanceof DateTimeInterface ? Carbon::instance($state) : Carbon::parse((string) $state);
        } catch (Throwable) {
            return null;
        }

        $date = $date->setTimezone($this->evaluate($this->dateTooltip['timezone']) ?? $this->getDisplayTimezone());
        $format = (string) $this->evaluate($this->dateTooltip['format']);

        return match ($this->dateTooltip['type']) {
            'since' => $date->diffForHumans(),
            'iso' => $date->isoFormat($format),
            default => $date->translatedFormat($format),
        };
    }

    /**
     * The `limit()` length, e.g. to show the full text in a tooltip only when it was cut:
     * `->tooltip(fn (TextColumn $column): ?string => mb_strlen($column->getState()) > $column->getCharacterLimit() ? $column->getState() : null)`.
     */
    public function getCharacterLimit(): ?int
    {
        $limit = $this->evaluate($this->characterLimit);

        return $limit === null ? null : (int) $limit;
    }

    public function getCharacterLimitEnd(): string
    {
        return (string) $this->evaluate($this->characterLimitEnd);
    }

    /**
     * With `limitList()`, show the hidden items behind a "+N more" button instead of a
     * fixed "and N more" text. All items are sent to the browser.
     */
    public function expandableLimitedList(bool|Closure $condition = true): static
    {
        $this->isLimitedListExpandable = $condition;

        return $this;
    }

    public function isLimitedListExpandable(): bool
    {
        return (bool) $this->evaluate($this->isLimitedListExpandable) && $this->evaluate($this->listLimit) !== null;
    }

    /**
     * Format the state; an expandable limited list keeps every item (the renderer hides
     * the rest).
     */
    protected function formatStateForExpandableDisplay(mixed $state): mixed
    {
        if (! $this->isLimitedListExpandable()) {
            return $this->formatStateForDisplay($state);
        }

        $limit = $this->listLimit;
        $this->listLimit = null;

        try {
            return $this->formatStateForDisplay($state);
        } finally {
            $this->listLimit = $limit;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getListLimitSchemaProps(): array
    {
        if (! $this->isLimitedListExpandable()) {
            return [];
        }

        return ['expandableLimitedList' => true, 'listLimit' => (int) $this->evaluate($this->listLimit)];
    }

    protected function formatDate(mixed $state): mixed
    {
        if ($this->isoDateFormat === null || $this->dateFormat !== $this->isoDateFormat || $this->evaluate($this->isSince)) {
            return $this->formatDateWithPhpFormat($state);
        }

        try {
            $date = self::toCarbon($state);
        } catch (Throwable) {
            return $state;
        }

        return $date->setTimezone($this->evaluateFormatOption($this->timezone, $state) ?? $this->getDisplayTimezone())
            ->isoFormat((string) $this->evaluate($this->dateFormat));
    }
}
