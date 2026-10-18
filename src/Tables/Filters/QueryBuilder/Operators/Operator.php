<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A custom operator added to a query builder constraint with `operators()`,
 * `pushOperators()` or `unshiftOperators()`:
 *
 * ```php
 * TextConstraint::make('email')->pushOperators([
 *     Operator::make('is_company')
 *         ->label('is a company address')
 *         ->query(fn (Builder $query, string $column) => $query->where($column, 'like', '%@acme.com')),
 * ])
 * ```
 *
 * By default a custom operator takes no value. Give it a value input with `input()`
 * (`text`, `number`, `date`, `datetime`, `month`, `year` or `select` with `options()`);
 * the value is validated for that input before `$value` reaches the query:
 *
 * ```php
 * Operator::make('longer_than')
 *     ->label('is longer than')
 *     ->input('number')
 *     ->query(fn (Builder $query, string $column, int|float $value) => $query->whereRaw('char_length('.$column.') > ?', [$value]))
 * ```
 *
 * The closure receives `$query` (already scoped to the rule, and to the related records
 * for relationship attributes), the qualified `$column`, the `$constraint` and `$value`.
 */
class Operator
{
    use Configurable;
    use EvaluatesClosures;

    /** Inputs a custom operator may ask for. */
    public const INPUTS = ['text', 'number', 'date', 'datetime', 'month', 'year', 'select'];

    protected string|Closure|null $label = null;

    protected ?Closure $query = null;

    protected string|Closure|null $input = null;

    /** @var array<string|int, string>|Closure */
    protected array|Closure $options = [];

    protected ?Closure $validateValueUsing = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        $static = new static($name);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'operator';
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return (string) ($this->evaluate($this->label) ?? mb_strtolower(Labels::fromName($this->name)));
    }

    /**
     * The value input the rule shows for this operator: `text`, `number`, `date`,
     * `datetime`, `month`, `year` or `select` (with `options()`). Null: no value.
     */
    public function input(string|Closure|null $input): static
    {
        $this->input = $input;

        return $this;
    }

    public function getInput(): ?string
    {
        $input = $this->evaluate($this->input);

        return is_string($input) && in_array($input, self::INPUTS, true) ? $input : null;
    }

    public function takesValue(): bool
    {
        return $this->getInput() !== null;
    }

    /**
     * Options of a `select` input, `value => label`.
     *
     * @param  array<string|int, string>|Closure  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return array<string|int, string>
     */
    public function getOptions(): array
    {
        return $this->evaluate($this->options) ?? [];
    }

    /**
     * Validate the value yourself: `fn (mixed $value): mixed` returns the normalised value,
     * or null to ignore the rule. Runs after the input's own validation.
     */
    public function validateValueUsing(?Closure $callback): static
    {
        $this->validateValueUsing = $callback;

        return $this;
    }

    /**
     * Normalise a value from the query string for the input, or null when it does not fit.
     */
    public function validateValue(mixed $value): mixed
    {
        $value = match ($this->getInput()) {
            'number' => is_numeric($value) ? $value + 0 : null,
            'year' => is_scalar($value) && ($year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]])) !== false ? $year : null,
            'date' => is_string($value) && self::matchesDate($value, 'Y-m-d') ? $value : null,
            'month' => is_string($value) && self::matchesDate($value, 'Y-m') ? $value : null,
            'datetime' => is_string($value) && self::matchesDate($value = str_replace(' ', 'T', substr($value, 0, 16)), 'Y-m-d\\TH:i') ? $value : null,
            'select' => is_scalar($value) && array_key_exists((string) $value, array_map(strval(...), $this->getOptions())) ? (string) $value : null,
            'text' => is_scalar($value) && ($text = trim((string) $value)) !== '' && mb_strlen($text) <= 255 ? $text : null,
            default => null,
        };

        if ($value === null || $this->validateValueUsing === null) {
            return $value;
        }

        return $this->evaluate($this->validateValueUsing, ['value' => $value]);
    }

    /**
     * The value as shown in the active-rule indicator.
     */
    public function formatValue(mixed $value): string
    {
        if ($this->getInput() === 'select') {
            return (string) ($this->getOptions()[$value] ?? $value);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function matchesDate(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    /**
     * `fn (Builder $query, string $column, Constraint $constraint, mixed $value)`.
     */
    public function query(?Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    public function apply(Builder $query, string $column, Constraint $constraint, mixed $value = null): void
    {
        if ($this->query === null) {
            return;
        }

        $this->evaluate($this->query, ['query' => $query, 'column' => $column, 'constraint' => $constraint, 'value' => $value], [Builder::class => $query, Constraint::class => $constraint]);
    }

    /**
     * Options of the `select` input for the renderer.
     *
     * @return list<array{value: string, label: string}>|null
     */
    public function getOptionsSchema(): ?array
    {
        if ($this->getInput() !== 'select') {
            return null;
        }

        $options = [];

        foreach ($this->getOptions() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }
}
