<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Summarizers;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The smallest and largest value: `TextColumn::make('published_at')->summarize(Range::make()->date())`.
 */
class Range extends Summarizer
{
    protected bool|Closure $isNullExcluded = true;

    protected bool|Closure $hasMinimalDateTimeDifference = false;

    protected bool|Closure $hasMinimalTextualDifference = false;

    /**
     * Show dates only when the bounds fall on different days, the date and time when
     * they share a day, and a single value when they are identical.
     */
    public function minimalDateTimeDifference(bool|Closure $condition = true): static
    {
        $this->hasMinimalDateTimeDifference = $condition;

        return $this;
    }

    /**
     * Show only as much of each bound as it takes to tell them apart ("A – C" for "Apple"
     * and "Cherry", "Ban – Bar" for "Banana" and "Bark"); identical bounds show once.
     */
    public function minimalTextualDifference(bool|Closure $condition = true): static
    {
        $this->hasMinimalTextualDifference = $condition;

        return $this;
    }

    /**
     * Whether empty values are left out (the default). With `false`, a column containing
     * nulls has no range.
     */
    public function excludeNull(bool|Closure $condition = true): static
    {
        $this->isNullExcluded = $condition;

        return $this;
    }

    protected function getDefaultLabel(): ?string
    {
        return __('alfakit::tables.summaries.range');
    }

    protected function summarize(QueryBuilder $query, string $attribute): mixed
    {
        if (! $this->evaluate($this->isNullExcluded) && (clone $query)->whereNull($attribute)->exists()) {
            return null;
        }

        $row = (clone $query)->selectRaw('min('.$query->getGrammar()->wrap($attribute).') as alfakit_min, max('.$query->getGrammar()->wrap($attribute).') as alfakit_max')->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        return [$row['alfakit_min'] ?? null, $row['alfakit_max'] ?? null];
    }

    protected function formatDateTimeRange(mixed $min, mixed $max): ?string
    {
        if ($min === null || $max === null || (! is_string($min) && ! $min instanceof DateTimeInterface) || (! is_string($max) && ! $max instanceof DateTimeInterface)) {
            return null;
        }

        try {
            $timezone = alfakit()->getTimezone();
            $from = Carbon::parse($min)->setTimezone($timezone);
            $to = Carbon::parse($max)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }

        if ($from->equalTo($to)) {
            return $from->isoFormat('LLL');
        }

        if ($from->isSameDay($to)) {
            return __('alfakit::tables.summaries.range_value', ['from' => $from->isoFormat('LLL'), 'to' => $to->isoFormat('LLL')]);
        }

        return __('alfakit::tables.summaries.range_value', ['from' => $from->isoFormat('LL'), 'to' => $to->isoFormat('LL')]);
    }

    /**
     * The shortest prefixes of the two bounds that still differ.
     *
     * @return array{0: string, 1: string}
     */
    public static function minimalTextualBounds(string $from, string $to): array
    {
        if ($from === $to) {
            return [$from, $to];
        }

        $length = 0;
        $max = max(mb_strlen($from), mb_strlen($to));

        while ($length < $max && mb_substr($from, 0, $length + 1) === mb_substr($to, 0, $length + 1)) {
            $length++;
        }

        return [mb_substr($from, 0, $length + 1), mb_substr($to, 0, $length + 1)];
    }

    public function formatSummaryState(mixed $state): ?string
    {
        if (! is_array($state)) {
            return parent::formatSummaryState($state);
        }

        if ($this->evaluate($this->hasMinimalDateTimeDifference) && ($range = $this->formatDateTimeRange($state[0] ?? null, $state[1] ?? null)) !== null) {
            return $range;
        }

        if ($this->evaluate($this->hasMinimalTextualDifference) && is_scalar($state[0] ?? null) && is_scalar($state[1] ?? null)) {
            [$from, $to] = self::minimalTextualBounds((string) $state[0], (string) $state[1]);

            return $from === $to ? $from : __('alfakit::tables.summaries.range_value', ['from' => $from, 'to' => $to]);
        }

        [$from, $to] = [parent::formatSummaryState($state[0] ?? null), parent::formatSummaryState($state[1] ?? null)];

        if ($from === null || $to === null || $from === $to) {
            return $from ?? $to;
        }

        return __('alfakit::tables.summaries.range_value', ['from' => $from, 'to' => $to]);
    }
}
