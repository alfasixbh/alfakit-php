<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Labels;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stringable;
use UnitEnum;

/**
 * One exported column. Dot names read relationships (`author.name`); `counts('comments')`,
 * `exists('comments')` and `avg()` / `sum()` / `min()` / `max()` export relationship
 * aggregates (eager loaded with `withAggregate()`).
 *
 * ```php
 * ExportColumn::make('id')->label('ID'),
 * ExportColumn::make('author.name'),
 * ExportColumn::make('price')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
 * ExportColumn::make('comments_count')->counts('comments'),
 * ExportColumn::make('comments_avg_rating')->avg('comments', 'rating'),
 * ExportColumn::make('internal_notes')->hidden(fn (): bool => ! auth()->user()->isAdmin()),
 * ```
 */
class ExportColumn
{
    use Configurable;
    use EvaluatesClosures;

    protected string|Closure|null $label = null;

    protected ?Closure $getStateUsing = null;

    protected ?Closure $formatStateUsing = null;

    protected bool|Closure $isEnabledByDefault = true;

    protected string|Closure|null $prefix = null;

    protected string|Closure|null $suffix = null;

    protected int|Closure|null $characterLimit = null;

    protected int|Closure|null $wordLimit = null;

    protected bool|Closure $isListedAsJson = false;

    protected string|Closure $listSeparator = ', ';

    /** @var array{relationship: string|array<string, Closure|null>, column: string|Expression|null, function: string}|null */
    protected ?array $aggregate = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    protected ?Model $record = null;

    protected ?Exporter $exporter = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        $static = new static($name);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'column';
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
        return (string) ($this->evaluate($this->label) ?? Labels::fromName($this->name));
    }

    /**
     * Compute the value instead of reading the attribute: `fn (Model $record) => …`.
     */
    public function state(?Closure $callback): static
    {
        $this->getStateUsing = $callback;

        return $this;
    }

    public function getStateUsing(?Closure $callback): static
    {
        return $this->state($callback);
    }

    public function formatStateUsing(?Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    /**
     * Whether the column is ticked in the export modal (default true).
     */
    /**
     * Filament compatibility: AlfaKit's CSV writer always prefixes cells starting with
     * `=`, `+`, `-`, `@`, a tab or a carriage return with `'`, so this is a no-op and
     * protection can't be turned off per column.
     */
    public function preventFormulaInjection(bool|Closure|null $condition = true): static
    {
        return $this;
    }

    /**
     * Always true: formula-injection protection is always on for CSV exports.
     */
    public function shouldPreventFormulaInjection(): bool
    {
        return true;
    }

    public function enabledByDefault(bool|Closure $condition = true): static
    {
        $this->isEnabledByDefault = $condition;

        return $this;
    }

    public function isEnabledByDefault(): bool
    {
        return (bool) $this->evaluate($this->isEnabledByDefault);
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

    public function limit(int|Closure|null $characters): static
    {
        $this->characterLimit = $characters;

        return $this;
    }

    public function words(int|Closure|null $words): static
    {
        $this->wordLimit = $words;

        return $this;
    }

    /**
     * Export lists (relationship values, array casts) as JSON instead of joining them.
     */
    public function listAsJson(bool|Closure $condition = true): static
    {
        $this->isListedAsJson = $condition;

        return $this;
    }

    public function listSeparator(string|Closure $separator): static
    {
        $this->listSeparator = $separator;

        return $this;
    }

    /**
     * Leave the column out of the export entirely (not offered in the modal, never written),
     * e.g. for columns only some users may export.
     */
    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->isHidden) || ! $this->evaluate($this->isVisible);
    }

    public function isVisible(): bool
    {
        return ! $this->isHidden();
    }

    /**
     * Export the number of related records (eager loaded with `withCount`). An array
     * scopes the count: `['comments' => fn (Builder $query) => $query->where('approved', true)]`.
     *
     * @param  string|array<string, Closure|null>  $relationships
     */
    public function counts(string|array $relationships): static
    {
        return $this->aggregate($relationships, null, 'count');
    }

    /**
     * Export whether related records exist (`withExists`), as a boolean.
     *
     * @param  string|array<string, Closure|null>  $relationships
     */
    public function exists(string|array $relationships): static
    {
        return $this->aggregate($relationships, null, 'exists');
    }

    /**
     * Export the average of `$column` over the related records (`withAvg`).
     *
     * @param  string|array<string, Closure|null>  $relationship
     */
    public function avg(string|array $relationship, string|Expression $column): static
    {
        return $this->aggregate($relationship, $column, 'avg');
    }

    /**
     * @param  string|array<string, Closure|null>  $relationship
     */
    public function sum(string|array $relationship, string|Expression $column): static
    {
        return $this->aggregate($relationship, $column, 'sum');
    }

    /**
     * @param  string|array<string, Closure|null>  $relationship
     */
    public function min(string|array $relationship, string|Expression $column): static
    {
        return $this->aggregate($relationship, $column, 'min');
    }

    /**
     * @param  string|array<string, Closure|null>  $relationship
     */
    public function max(string|array $relationship, string|Expression $column): static
    {
        return $this->aggregate($relationship, $column, 'max');
    }

    /**
     * @param  string|array<string, Closure|null>  $relationship
     */
    protected function aggregate(string|array $relationship, string|Expression|null $column, string $function): static
    {
        $this->aggregate = ['relationship' => $relationship, 'column' => $column, 'function' => $function];

        return $this;
    }

    /**
     * @return array{relationship: string|array<string, Closure|null>, column: string|Expression|null, function: string}|null
     */
    public function getAggregate(): ?array
    {
        return $this->aggregate;
    }

    public function getCountedRelationship(): ?string
    {
        return $this->aggregate !== null && $this->aggregate['function'] === 'count' ? $this->getAggregatedRelationshipName() : null;
    }

    public function getAggregatedRelationshipName(): ?string
    {
        if ($this->aggregate === null) {
            return null;
        }

        $relationship = $this->aggregate['relationship'];

        return is_array($relationship) ? (string) array_key_first($relationship) : $relationship;
    }

    /**
     * The attribute Laravel's `withAggregate()` adds (`comments_count`, `comments_exists`,
     * `comments_avg_rating`).
     */
    public function getAggregateAttribute(): ?string
    {
        $name = $this->getAggregatedRelationshipName();

        if ($name === null || $this->aggregate === null) {
            return null;
        }

        // As Laravel names it: a raw expression (`DB::raw('price * qty')`) by its SQL.
        $column = $this->aggregate['column'];
        $column = $column instanceof Expression ? (string) $column->getValue(DB::connection()->getQueryGrammar()) : ($column ?? '*');

        return Str::snake((string) preg_replace('/[^[:alnum:][:space:]_]/u', ' ', "{$name} {$this->aggregate['function']} {$column}"));
    }

    /**
     * Add this column's aggregate to the export query.
     *
     * @param  Builder<Model>  $query
     */
    public function applyAggregate(Builder $query): void
    {
        if ($this->aggregate === null) {
            return;
        }

        $relationship = $this->aggregate['relationship'];
        $relations = is_array($relationship)
            ? array_map(static fn (?Closure $constraint): Closure => $constraint ?? static fn () => null, $relationship)
            : [$relationship];

        $query->withAggregate($relations, $this->aggregate['column'] ?? '*', $this->aggregate['function']);
    }

    /**
     * Relationship paths to eager load for this column.
     *
     * @return array<string>
     */
    public function getRelationshipsToEagerLoad(Model $model): array
    {
        if ($this->getStateUsing !== null || ! str_contains($this->name, '.')) {
            return [];
        }

        $segments = explode('.', $this->name);
        array_pop($segments);

        $path = [];
        $current = $model;

        foreach ($segments as $segment) {
            if (! method_exists($current, $segment)) {
                break;
            }

            $relation = $current->{$segment}();

            if (! $relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                break;
            }

            $path[] = $segment;
            $current = $relation->getRelated();
        }

        return $path === [] ? [] : [implode('.', $path)];
    }

    public function exporter(?Exporter $exporter): static
    {
        $this->exporter = $exporter;

        return $this;
    }

    public function record(?Model $record): static
    {
        $this->record = $record;

        return $this;
    }

    /**
     * The raw value for a record.
     */
    public function getState(Model $record): mixed
    {
        $this->record = $record;

        if ($this->getStateUsing !== null) {
            return $this->evaluate($this->getStateUsing);
        }

        if (($attribute = $this->getAggregateAttribute()) !== null) {
            return $record->getAttribute($attribute);
        }

        return $this->readPath($record, explode('.', $this->name));
    }

    /**
     * Read a dot path, plucking through to-many relationships (`tags.name` → a list).
     *
     * @param  array<string>  $segments
     */
    protected function readPath(mixed $target, array $segments): mixed
    {
        foreach ($segments as $index => $segment) {
            if ($target instanceof Collection || (is_array($target) && array_is_list($target))) {
                $rest = array_slice($segments, $index);

                return collect($target)->map(fn (mixed $item): mixed => $this->readPath($item, $rest))->flatten()->all();
            }

            $target = data_get($target, $segment);

            if ($target === null) {
                return null;
            }
        }

        return $target;
    }

    /**
     * The value written to the file: formatted, prefixed/suffixed and flattened to a
     * scalar (lists are joined, or JSON with `listAsJson()`).
     */
    public function getFormattedState(Model $record): string|int|float|bool|null
    {
        $state = $this->getState($record);

        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if ($this->formatStateUsing !== null) {
            $state = is_array($state) && array_is_list($state) && ! $this->evaluate($this->isListedAsJson)
                ? array_map(fn (mixed $item): mixed => $this->evaluate($this->formatStateUsing, ['state' => $item]), $state)
                : $this->evaluate($this->formatStateUsing, ['state' => $state]);
        }

        if (is_array($state)) {
            $state = $this->evaluate($this->isListedAsJson)
                ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : implode((string) $this->evaluate($this->listSeparator), array_map(fn (mixed $item): string => (string) $this->toScalar($item), Arr::flatten($state)));
        }

        $state = $this->toScalar($state);

        if (is_string($state)) {
            if (($limit = $this->evaluate($this->characterLimit)) !== null) {
                $state = Str::limit($state, (int) $limit);
            }

            if (($words = $this->evaluate($this->wordLimit)) !== null) {
                $state = Str::words($state, (int) $words);
            }
        }

        $prefix = $this->evaluate($this->prefix);
        $suffix = $this->evaluate($this->suffix);

        if ($state !== null && ($prefix !== null || $suffix !== null)) {
            $state = $prefix.(is_bool($state) ? ($state ? '1' : '0') : (string) $state).$suffix;
        }

        return $state;
    }

    protected function toScalar(mixed $state): string|int|float|bool|null
    {
        return match (true) {
            $state === null, is_scalar($state) => $state,
            $state instanceof BackedEnum => $state->value,
            $state instanceof UnitEnum => $state->name,
            $state instanceof DateTimeInterface => \Illuminate\Support\Carbon::instance($state)->setTimezone(alfakit()->getTimezone())->format('Y-m-d H:i:s'),
            $state instanceof Htmlable => strip_tags($state->toHtml()),
            $state instanceof Model => (string) $state->getKey(),
            $state instanceof Stringable => (string) $state,
            default => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'record' => [$this->record],
            'exporter' => [$this->exporter],
            'options' => [$this->exporter?->getOptions() ?? []],
            'export' => [$this->exporter?->getExport()],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return match (true) {
            $this->record instanceof $parameterType => [$this->record],
            $this->exporter instanceof $parameterType => [$this->exporter],
            default => [],
        };
    }
}
