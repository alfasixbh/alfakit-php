<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Grouping;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasEnumState;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\RecordState;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Groups table rows by an attribute, a relationship attribute (`author.name`) or a date.
 *
 * ```php
 * $table->groups([
 *     Group::make('status')->collapsible(),
 *     Group::make('author.name')->label('Author'),
 *     Group::make('published_at')->date(),
 * ])->defaultGroup('status')
 * ```
 *
 * An enum state (an attribute cast to an enum, or a raw value with `->enum(Status::class)`)
 * is titled with the enum's label, and its colour and icon are shown next to the title.
 *
 * Rows are ordered by the group first, so each group's rows are contiguous on every page.
 */
class Group
{
    use Configurable;
    use EvaluatesClosures;
    use HasEnumState;

    protected string $id;

    protected ?string $column;

    protected string|Closure|null $label = null;

    protected ?Closure $getTitleFromRecordUsing = null;

    protected ?Closure $getDescriptionFromRecordUsing = null;

    protected ?Closure $getKeyFromRecordUsing = null;

    protected ?Closure $orderQueryUsing = null;

    protected ?Closure $scopeQueryByKeyUsing = null;

    protected ?Closure $groupQueryUsing = null;

    protected bool|Closure $isCollapsible = false;

    protected bool|Closure $isTitlePrefixedWithLabel = true;

    protected bool|Closure $isDate = false;

    protected string|Closure|null $dateFormat = null;

    /** @var Model|array<mixed>|null */
    protected Model|array|null $record = null;

    final public function __construct(string $id, ?string $column = null)
    {
        $this->id = $id;
        $this->column = $column ?? $id;
    }

    public static function make(string $id, ?string $column = null): static
    {
        $static = new static($id, $column);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'group';
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function column(?string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function getColumn(): string
    {
        return $this->column ?? $this->id;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? Labels::fromName($this->getColumn());
    }

    /**
     * `fn (Model|array $record): string` — the heading shown above the group's rows.
     */
    public function getTitleFromRecordUsing(?Closure $callback): static
    {
        $this->getTitleFromRecordUsing = $callback;

        return $this;
    }

    /**
     * `fn (Model|array $record): ?string` — a line shown under the group title.
     */
    public function getDescriptionFromRecordUsing(?Closure $callback): static
    {
        $this->getDescriptionFromRecordUsing = $callback;

        return $this;
    }

    /**
     * `fn (Model|array $record): ?string` — what makes two rows belong to the same group. Pair it
     * with `scopeQueryByKeyUsing()` so group summaries and "groups only" tables agree.
     */
    public function getKeyFromRecordUsing(?Closure $callback): static
    {
        $this->getKeyFromRecordUsing = $callback;

        return $this;
    }

    /**
     * `fn (Builder $query, string $direction): Builder` — how rows are ordered by group.
     */
    public function orderQueryUsing(?Closure $callback): static
    {
        $this->orderQueryUsing = $callback;

        return $this;
    }

    /**
     * `fn (Builder $query, ?string $key): Builder` — limit a query to one group's rows.
     */
    public function scopeQueryByKeyUsing(?Closure $callback): static
    {
        $this->scopeQueryByKeyUsing = $callback;

        return $this;
    }

    /**
     * `fn (Builder $query): Builder` — group a query by this group
     * (`$query->groupBy('status')`). Groups-only tables then find their groups with this
     * one grouped query instead of reading every matching record.
     */
    public function groupQueryUsing(?Closure $callback): static
    {
        $this->groupQueryUsing = $callback;

        return $this;
    }

    public function hasGroupQuery(): bool
    {
        return $this->groupQueryUsing !== null;
    }

    /**
     * The query grouped by this group (one row per group), or null without
     * `groupQueryUsing()`. Without its own `select()`, the group column is selected.
     */
    public function groupQuery(Builder $query): ?Builder
    {
        if ($this->groupQueryUsing === null) {
            return null;
        }

        $query = $this->evaluate($this->groupQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;

        if (($query->getQuery()->columns ?? []) === [] && ! $this->isRelationship()) {
            $query->select($query->qualifyColumn($this->getColumn()));
        }

        return $query;
    }

    public function collapsible(bool|Closure $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    public function isCollapsible(): bool
    {
        return (bool) $this->evaluate($this->isCollapsible);
    }

    /**
     * Whether titles read "Status: Draft" (the default) or just "Draft".
     */
    public function titlePrefixedWithLabel(bool|Closure $condition = true): static
    {
        $this->isTitlePrefixedWithLabel = $condition;

        return $this;
    }

    /**
     * Group a date or date-time attribute by calendar day, in the panel's timezone.
     */
    public function date(bool|Closure $condition = true, string|Closure|null $format = null): static
    {
        $this->isDate = $condition;
        $this->dateFormat = $format;

        return $this;
    }

    public function isDate(): bool
    {
        return (bool) $this->evaluate($this->isDate);
    }

    public function isRelationship(): bool
    {
        return str_contains($this->getColumn(), '.');
    }

    public function getRelationshipName(): ?string
    {
        return $this->isRelationship() ? Str::beforeLast($this->getColumn(), '.') : null;
    }

    // ---------------------------------------------------------------- Records

    /**
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    public function getKey(Model|array $record): ?string
    {
        if ($this->getKeyFromRecordUsing !== null) {
            $key = $this->evaluateForRecord($this->getKeyFromRecordUsing, $record);

            return $key === null ? null : $this->stringify($key);
        }

        $state = RecordState::get($record, $this->getColumn());

        if (is_array($state)) {
            $state = $state[0] ?? null;
        }

        if ($state === null || $state === '') {
            return null;
        }

        if ($this->isDate()) {
            $date = $this->toDate($state);

            return $date?->format('Y-m-d');
        }

        return $this->stringify($state);
    }

    /**
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    public function getTitle(Model|array $record): string
    {
        if ($this->getTitleFromRecordUsing !== null) {
            $title = (string) $this->stringify($this->evaluateForRecord($this->getTitleFromRecordUsing, $record));
        } else {
            $title = $this->getDefaultTitle($record);
        }

        if (! $this->evaluate($this->isTitlePrefixedWithLabel)) {
            return $title;
        }

        return __('alfakit::tables.grouping.title', ['label' => $this->getLabel(), 'title' => $title]);
    }

    /**
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    protected function getDefaultTitle(Model|array $record): string
    {
        $state = RecordState::get($record, $this->getColumn());

        if (is_array($state)) {
            $state = $state[0] ?? null;
        }

        if ($state === null || $state === '') {
            return __('alfakit::tables.grouping.empty');
        }

        if ($this->isDate()) {
            $date = $this->toDate($state);

            if ($date !== null) {
                return $date->translatedFormat($this->evaluate($this->dateFormat) ?? 'M j, Y');
            }
        }

        if (is_bool($state)) {
            return __($state ? 'alfakit::tables.filters.ternary.true' : 'alfakit::tables.filters.ternary.false');
        }

        if (($case = $this->resolveEnumCase($state)) !== null) {
            return EnumState::label($case);
        }

        return (string) $this->stringify($state);
    }

    /**
     * The enum case of a record's group state: the cast attribute, or the raw value
     * turned back into a case of `->enum()`. Null with a custom title or key.
     *
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    protected function getEnumCase(Model|array $record): ?UnitEnum
    {
        if ($this->getTitleFromRecordUsing !== null || $this->getKeyFromRecordUsing !== null || $this->isDate()) {
            return null;
        }

        $state = RecordState::get($record, $this->getColumn());

        if (is_array($state)) {
            $state = $state[0] ?? null;
        }

        return $state === null || $state === '' ? null : $this->resolveEnumCase($state);
    }

    /**
     * The enum colour (`HasColor`) of a record's group, shown on the title's icon.
     *
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    public function getColor(Model|array $record): ?string
    {
        $case = $this->getEnumCase($record);

        return $case === null ? null : EnumState::color($case);
    }

    /**
     * The enum icon (`HasIcon`) of a record's group, shown before the title.
     *
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    public function getIcon(Model|array $record): ?string
    {
        $case = $this->getEnumCase($record);

        return $case === null ? null : EnumState::icon($case);
    }

    /**
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    public function getDescription(Model|array $record): ?string
    {
        if ($this->getDescriptionFromRecordUsing === null) {
            return null;
        }

        return $this->stringify($this->evaluateForRecord($this->getDescriptionFromRecordUsing, $record));
    }

    // ---------------------------------------------------------------- Queries

    /**
     * Order the table query by this group (before the table's own sort).
     */
    public function orderQuery(Builder $query, string $direction, Table $table): Builder
    {
        if ($this->orderQueryUsing !== null) {
            return $this->evaluate($this->orderQueryUsing, ['query' => $query, 'direction' => $direction], [Builder::class => $query]) ?? $query;
        }

        // Columns already know how to sort by a (nested) relationship attribute.
        TextColumn::make($this->getColumn())->table($table)->applySort($query, $direction);

        return $query;
    }

    /**
     * Limit a query to the rows of the group identified by `$key`.
     */
    public function scopeQueryByKey(Builder $query, ?string $key): Builder
    {
        if ($this->scopeQueryByKeyUsing !== null) {
            return $this->evaluate($this->scopeQueryByKeyUsing, ['query' => $query, 'key' => $key], [Builder::class => $query]) ?? $query;
        }

        $relationship = $this->getRelationshipName();

        if ($relationship !== null) {
            $attribute = Str::afterLast($this->getColumn(), '.');

            return $key === null
                ? $query->whereDoesntHave($relationship, fn (Builder $q) => $q->whereNotNull($q->qualifyColumn($attribute)))
                : $query->whereHas($relationship, fn (Builder $q) => $this->scopeColumn($q, $q->qualifyColumn($attribute), $key));
        }

        $column = $query->qualifyColumn($this->getColumn());

        return $key === null ? $query->whereNull($column) : $this->scopeColumn($query, $column, $key);
    }

    protected function scopeColumn(Builder $query, string $column, string $key): Builder
    {
        if (! $this->isDate()) {
            return $query->where($column, $key);
        }

        // Day boundaries in the panel's timezone, compared in the application's timezone
        // (the one dates are stored in).
        try {
            $day = Carbon::createFromFormat('Y-m-d', $key, alfakit()->getTimezone());
        } catch (Throwable) {
            return $query->whereRaw('1 = 0');
        }

        if ($day === null) {
            return $query->whereRaw('1 = 0');
        }

        $storage = (string) config('app.timezone', 'UTC');

        return $query->whereBetween($column, [
            $day->copy()->startOfDay()->setTimezone($storage)->toDateTimeString(),
            $day->copy()->endOfDay()->setTimezone($storage)->toDateTimeString(),
        ]);
    }

    // ---------------------------------------------------------------- Helpers

    protected function toDate(mixed $state): ?Carbon
    {
        try {
            $date = $state instanceof DateTimeInterface ? Carbon::instance($state) : Carbon::parse((string) $state);
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(alfakit()->getTimezone());
    }

    protected function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => null,
        };
    }

    /**
     * @param  Model|array<mixed>  $record
     */
    protected function evaluateForRecord(Closure $callback, Model|array $record): mixed
    {
        $this->record = $record;

        try {
            return $this->evaluate($callback, ['record' => $record], $record instanceof Model ? [Model::class => $record, $record::class => $record] : []);
        } finally {
            $this->record = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'id' => $this->getId(),
            'label' => $this->getLabel(),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'record' => [$this->record],
            default => [],
        };
    }
}
