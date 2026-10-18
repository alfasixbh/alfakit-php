<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Date rules: is after, is before, is on, is not on, is month, is year, is filled, is
 * blank. Days, months and years are the panel timezone's, compared against values stored
 * in the application timezone.
 *
 * `time()` makes "is after" / "is before" compare a date and time (for datetime columns).
 */
class DateConstraint extends Constraint
{
    protected bool|Closure $hasTime = false;

    /**
     * "Is after" / "is before" take a date and time instead of a day.
     */
    public function time(bool|Closure $condition = true): static
    {
        $this->hasTime = $condition;

        return $this;
    }

    public function hasTime(): bool
    {
        return (bool) $this->evaluate($this->hasTime);
    }

    public function getType(): string
    {
        return 'date';
    }

    public function getOperators(): array
    {
        return [
            'is_after' => __('alfakit::tables.query_builder.operators.is_after'),
            'is_before' => __('alfakit::tables.query_builder.operators.is_before'),
            'is_date' => __('alfakit::tables.query_builder.operators.is_date'),
            'is_not_date' => __('alfakit::tables.query_builder.operators.is_not_date'),
            'is_month' => __('alfakit::tables.query_builder.operators.is_month'),
            'is_year' => __('alfakit::tables.query_builder.operators.is_year'),
            'is_filled' => __('alfakit::tables.query_builder.operators.is_filled'),
            'is_blank' => __('alfakit::tables.query_builder.operators.is_blank'),
        ];
    }

    protected function getOperatorInput(string $operator): string
    {
        return match ($operator) {
            'is_month' => 'month',
            'is_year' => 'year',
            'is_after', 'is_before' => $this->hasTime() ? 'datetime' : 'date',
            default => 'date',
        };
    }

    protected function validateValue(string $operator, mixed $value): ?array
    {
        $input = $this->getOperatorInput($operator);

        if ($input === 'year') {
            $year = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]) : false;

            return $year === false ? null : ['value' => $year];
        }

        if (! is_string($value)) {
            return null;
        }

        [$pattern, $format] = match ($input) {
            'month' => ['/^\d{4}-\d{2}$/', 'Y-m'],
            'datetime' => ['/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', 'Y-m-d\TH:i'],
            default => ['/^\d{4}-\d{2}-\d{2}$/', 'Y-m-d'],
        };

        // Datetime inputs may send seconds or a space; keep minutes.
        if ($input === 'datetime') {
            $value = substr(str_replace(' ', 'T', $value), 0, 16);
        }

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!'.$format, $value, alfakit()->getTimezone());
        } catch (Throwable) {
            return null;
        }

        return $date === null || $date->format($format) !== $value ? null : ['value' => $value];
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        if ($operator === 'is_filled') {
            $query->whereNotNull($column);

            return;
        }

        if ($operator === 'is_blank') {
            $query->whereNull($column);

            return;
        }

        $timezone = alfakit()->getTimezone();
        $storage = (string) config('app.timezone', 'UTC');
        $input = $this->getOperatorInput($operator);

        if ($input === 'datetime') {
            $moment = Carbon::createFromFormat('!Y-m-d\TH:i', (string) $value, $timezone);

            if ($moment === null) {
                return;
            }

            // "After 10:15" starts at 10:16; "before 10:15" ends at 10:14:59.
            $operator === 'is_after'
                ? $query->where($column, '>', $moment->copy()->endOfMinute()->setTimezone($storage)->toDateTimeString())
                : $query->where($column, '<', $moment->copy()->setTimezone($storage)->toDateTimeString());

            return;
        }

        $period = match ($input) {
            'month' => Carbon::createFromFormat('!Y-m', (string) $value, $timezone),
            'year' => Carbon::create((int) $value, 1, 1, 0, 0, 0, $timezone),
            default => Carbon::createFromFormat('!Y-m-d', (string) $value, $timezone),
        };

        if ($period === null) {
            return;
        }

        [$from, $to] = match ($input) {
            'month' => [$period->copy()->startOfMonth(), $period->copy()->endOfMonth()],
            'year' => [$period->copy()->startOfYear(), $period->copy()->endOfYear()],
            default => [$period->copy()->startOfDay(), $period->copy()->endOfDay()],
        };

        $start = $from->setTimezone($storage)->toDateTimeString();
        $end = $to->setTimezone($storage)->toDateTimeString();

        match ($operator) {
            'is_after' => $query->where($column, '>', $end),
            'is_before' => $query->where($column, '<', $start),
            'is_not_date' => $query->where(static fn (Builder $q) => $q->whereNotBetween($column, [$start, $end])->orWhereNull($column)),
            default => $query->whereBetween($column, [$start, $end]),
        };
    }

    protected function formatIndicatorValue(string $operator, mixed $value): string
    {
        $timezone = alfakit()->getTimezone();

        try {
            return match ($this->getOperatorInput($operator)) {
                'year' => (string) $value,
                'month' => Carbon::createFromFormat('!Y-m', (string) $value, $timezone)?->translatedFormat('F Y') ?? (string) $value,
                'datetime' => Carbon::createFromFormat('!Y-m-d\TH:i', (string) $value, $timezone)?->translatedFormat('M j, Y H:i') ?? (string) $value,
                default => Carbon::createFromFormat('!Y-m-d', (string) $value, $timezone)?->translatedFormat('M j, Y') ?? (string) $value,
            };
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
