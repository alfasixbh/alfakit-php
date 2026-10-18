<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports;

use AlfaSix\Alfakit\Actions\Imports\Exceptions\RowImportFailedException;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\TenantScope;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use ReflectionEnum;

/**
 * One importable attribute: which CSV column feeds it (guessed from the header), how its
 * text is cast, how it is validated and how it is written to the record.
 *
 * ```php
 * ImportColumn::make('sku')->label('SKU')->requiredMapping()->rules(['required', 'max:32']),
 * ImportColumn::make('price')->numeric(decimalPlaces: 2)->rules(['numeric', 'min:0']),
 * ImportColumn::make('author')->relationship(resolveUsing: 'email'),
 * ImportColumn::make('tags')->relationship(resolveUsing: 'name')->multiple(','),
 * ```
 */
class ImportColumn
{
    use Configurable;
    use EvaluatesClosures;

    protected string|Closure|null $label = null;

    protected bool|Closure $isMappingRequired = false;

    /** @var array<mixed>|Closure */
    protected array|Closure $rules = [];

    /** @var array<mixed>|Closure */
    protected array|Closure $nestedRecursiveRules = [];

    /** @var array<string> */
    protected array $guesses = [];

    /** @var array<mixed>|Closure|null Null: the `enum()` values, else none. */
    protected array|Closure|null $examples = null;

    /** @var class-string<BackedEnum>|Closure|null */
    protected string|Closure|null $enum = null;

    protected string|Closure|null $exampleHeader = null;

    protected string|Closure|null $helperText = null;

    protected bool $isNumeric = false;

    protected ?int $decimalPlaces = null;

    protected bool $isInteger = false;

    protected bool $isBoolean = false;

    protected ?string $arraySeparator = null;

    protected ?Closure $castStateUsing = null;

    protected ?Closure $fillRecordUsing = null;

    protected bool|Closure $isBlankStateIgnored = false;

    protected ?string $relationshipName = null;

    /** @var string|array<string>|Closure|null */
    protected string|array|Closure|null $relationshipResolveUsing = null;

    protected bool $hasRelationship = false;

    /**
     * BelongsToMany: the related keys of the current row, synced once the record is saved.
     *
     * @var array<int, mixed>|null
     */
    protected ?array $pendingRelatedKeys = null;

    protected ?Importer $importer = null;

    protected bool|Closure $isSensitive = false;

    protected string|Closure|null $validationAttribute = null;

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
     * The name used for this column in validation messages ("The :attribute field is
     * required."). Defaults to the label.
     */
    public function validationAttribute(string|Closure|null $attribute): static
    {
        $this->validationAttribute = $attribute;

        return $this;
    }

    public function getValidationAttribute(): string
    {
        return (string) ($this->evaluate($this->validationAttribute) ?? $this->getLabel());
    }

    /**
     * Keep this column's value out of the failed rows (stored in the database and offered
     * as a CSV download), e.g. for passwords or personal data.
     */
    public function sensitive(bool|Closure $condition = true): static
    {
        $this->isSensitive = $condition;

        return $this;
    }

    public function isSensitive(): bool
    {
        return (bool) $this->evaluate($this->isSensitive);
    }

    /**
     * The user must pick a CSV column for this attribute before the import can start.
     */
    public function requiredMapping(bool|Closure $condition = true): static
    {
        $this->isMappingRequired = $condition;

        return $this;
    }

    public function isMappingRequired(): bool
    {
        return (bool) $this->evaluate($this->isMappingRequired);
    }

    /**
     * Laravel validation rules for each row's (cast) value.
     *
     * @param  array<mixed>|Closure  $rules
     */
    public function rules(array|Closure $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getValidationRules(): array
    {
        $rules = array_values((array) $this->evaluate($this->rules));

        if (! $this->isArray() && ($enum = $this->getBackedEnum()) !== null) {
            $rules[] = 'nullable';
            $rules[] = Rule::enum($enum);
        }

        return $rules;
    }

    /**
     * Rules for each item of an `array()` / `multiple()` column (`tags.*`), e.g.
     * `->multiple()->nestedRecursiveRules(['integer', 'exists:tags,id'])`.
     *
     * @param  array<mixed>|Closure  $rules
     */
    public function nestedRecursiveRules(array|Closure $rules): static
    {
        $this->nestedRecursiveRules = $rules;

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getNestedRecursiveValidationRules(): array
    {
        $rules = array_values((array) $this->evaluate($this->nestedRecursiveRules));

        if ($this->isArray() && ($enum = $this->getBackedEnum()) !== null) {
            $rules[] = Rule::enum($enum);
        }

        return $rules;
    }

    /**
     * Only accept the values of a backed enum (each item of a `multiple()` column), as in
     * Filament: `ImportColumn::make('status')->enum(Status::class)`. Blank cells pass
     * (add `required` to `rules()` to refuse them), numeric cells of an int-backed enum
     * are cast to integers, and the enum's values become the default `examples()`.
     *
     * @param  class-string<BackedEnum>|Closure|null  $enum
     */
    public function enum(string|Closure|null $enum): static
    {
        $this->enum = $enum;

        return $this;
    }

    /**
     * @return class-string<BackedEnum>|null
     */
    public function getEnum(): ?string
    {
        $enum = $this->evaluate($this->enum);

        return is_string($enum) && $enum !== '' ? $enum : null;
    }

    /**
     * @return class-string<BackedEnum>|null
     */
    protected function getBackedEnum(): ?string
    {
        $enum = $this->getEnum();

        if ($enum === null) {
            return null;
        }

        if (! is_a($enum, BackedEnum::class, allow_string: true)) {
            throw new InvalidArgumentException("Enum [{$enum}] must be a backed enum.");
        }

        return $enum;
    }

    /**
     * Extra CSV headers that map to this column automatically, e.g. `['e-mail', 'mail']`.
     *
     * @param  array<string>  $guesses
     */
    public function guess(array $guesses): static
    {
        $this->guesses = $guesses;

        return $this;
    }

    /**
     * Headers that match this column: its name, its label and the explicit guesses.
     *
     * @return array<string>
     */
    public function getGuesses(): array
    {
        return array_values(array_unique([
            $this->name,
            Str::afterLast($this->name, '.'),
            $this->getLabel(),
            ...$this->guesses,
        ]));
    }

    /**
     * Pick the CSV header that matches this column best, comparing headers without case,
     * spaces, dashes or underscores.
     *
     * @param  array<string>  $headers
     */
    public function guessHeader(array $headers): ?string
    {
        $normalized = [];

        foreach ($headers as $header) {
            $normalized[static::normalizeHeader($header)] ??= $header;
        }

        foreach ($this->getGuesses() as $guess) {
            $key = static::normalizeHeader($guess);

            if ($key !== '' && isset($normalized[$key])) {
                return $normalized[$key];
            }
        }

        return null;
    }

    public static function normalizeHeader(string $header): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower(trim($header)));
    }

    /**
     * @param  string|array<mixed>|Closure|null  $example
     */
    public function example(string|array|Closure|null $example): static
    {
        $this->examples = $example === null ? [] : ($example instanceof Closure ? $example : (array) $example);

        return $this;
    }

    /**
     * @param  array<mixed>|Closure  $examples
     */
    public function examples(array|Closure $examples): static
    {
        $this->examples = $examples;

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getExamples(): array
    {
        if ($this->examples !== null) {
            return array_values((array) $this->evaluate($this->examples));
        }

        // A closure-resolved enum may depend on the import, so it gives no default examples.
        if ($this->enum instanceof Closure || ($enum = $this->getBackedEnum()) === null) {
            return [];
        }

        return array_map(static fn (BackedEnum $case): string|int => $case->value, $enum::cases());
    }

    public function exampleHeader(string|Closure|null $header): static
    {
        $this->exampleHeader = $header;

        return $this;
    }

    public function getExampleHeader(): string
    {
        return (string) ($this->evaluate($this->exampleHeader) ?? $this->name);
    }

    public function helperText(string|Closure|null $text): static
    {
        $this->helperText = $text;

        return $this;
    }

    public function getHelperText(): ?string
    {
        return $this->evaluate($this->helperText);
    }

    // ---------------------------------------------------------------- Casting

    /**
     * Cast the value to a number (rounded to `decimalPlaces` when given). Values that are
     * not numeric are kept as text so the `numeric` rule can reject them.
     */
    public function numeric(bool $condition = true, ?int $decimalPlaces = null): static
    {
        $this->isNumeric = $condition;
        $this->decimalPlaces = $decimalPlaces;

        return $this;
    }

    public function integer(bool $condition = true): static
    {
        $this->isInteger = $condition;

        return $this;
    }

    /**
     * Read yes/no, true/false, on/off and 1/0 as booleans.
     */
    public function boolean(bool $condition = true): static
    {
        $this->isBoolean = $condition;

        return $this;
    }

    /**
     * Split the value into a list, e.g. `red, green` → `['red', 'green']`.
     */
    public function array(?string $separator = ','): static
    {
        $this->arraySeparator = $separator;

        return $this;
    }

    /**
     * Several values in one cell, e.g. the tags of a BelongsToMany `relationship()`
     * (`php, laravel`). Same as `array()`.
     */
    public function multiple(string $separator = ','): static
    {
        return $this->array($separator);
    }

    public function isArray(): bool
    {
        return $this->arraySeparator !== null;
    }

    public function castStateUsing(?Closure $callback): static
    {
        $this->castStateUsing = $callback;

        return $this;
    }

    public function castState(mixed $state): mixed
    {
        $originalState = $state;

        if (is_string($state)) {
            $state = trim($state);
        }

        if ($state === '') {
            $state = null;
        }

        if ($this->isArray()) {
            $items = $state === null ? [] : explode((string) $this->arraySeparator, (string) $state);
            $state = array_values(array_filter(array_map(fn (string $item): mixed => $this->castScalar(trim($item) === '' ? null : trim($item)), $items), static fn (mixed $item): bool => $item !== null));
        } else {
            $state = $this->castScalar($state);
        }

        if ($this->castStateUsing !== null) {
            $state = $this->evaluate($this->castStateUsing, ['state' => $state, 'originalState' => $originalState]);
        }

        return $state;
    }

    protected function castScalar(mixed $state): mixed
    {
        if ($state === null) {
            return null;
        }

        // `enum()` with an int-backed enum: `"2"` validates and fills as `2`.
        if (is_string($state) && preg_match('/^-?\d+$/', $state) === 1 && ($enum = $this->getBackedEnum()) !== null
            && (string) (new ReflectionEnum($enum))->getBackingType() === 'int') {
            return (int) $state;
        }

        if ($this->isBoolean) {
            $boolean = filter_var($state, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $boolean ?? $state;
        }

        if ($this->isInteger) {
            return is_numeric($state) && (float) $state == (int) $state ? (int) $state : $state;
        }

        if ($this->isNumeric) {
            if (! is_numeric($state)) {
                return $state;
            }

            return $this->decimalPlaces !== null ? round((float) $state, $this->decimalPlaces) : $state + 0;
        }

        return $state;
    }

    // ---------------------------------------------------------------- Filling

    /**
     * Leave the attribute alone when the CSV cell is blank (useful when updating records).
     */
    public function ignoreBlankState(bool|Closure $condition = true): static
    {
        $this->isBlankStateIgnored = $condition;

        return $this;
    }

    public function isBlankStateIgnored(): bool
    {
        return (bool) $this->evaluate($this->isBlankStateIgnored);
    }

    /**
     * Write the value yourself: `fn (Model $record, mixed $state) => …`.
     */
    public function fillRecordUsing(?Closure $callback): static
    {
        $this->fillRecordUsing = $callback;

        return $this;
    }

    /**
     * Fill a BelongsTo or BelongsToMany relationship. The CSV value is matched against the
     * related key, or the given attribute(s) (`'email'`, `['email', 'username']`), or
     * resolved by a closure receiving `$state` and returning the related model. Related
     * records are limited to the current tenant. A BelongsToMany cell holds several values
     * (`multiple(',')`) and is synced after the record is saved.
     *
     * @param  string|array<string>|Closure|null  $resolveUsing
     */
    public function relationship(?string $name = null, string|array|Closure|null $resolveUsing = null): static
    {
        $this->hasRelationship = true;
        $this->relationshipName = $name;
        $this->relationshipResolveUsing = $resolveUsing;

        return $this;
    }

    public function hasRelationship(): bool
    {
        return $this->hasRelationship;
    }

    public function getRelationshipName(): string
    {
        return $this->relationshipName ?? Str::camel(Str::beforeLast($this->name, '_id'));
    }

    public function importer(?Importer $importer): static
    {
        $this->importer = $importer;

        return $this;
    }

    public function getImporter(): ?Importer
    {
        return $this->importer;
    }

    public function fillRecord(Model $record, mixed $state): void
    {
        if ($this->fillRecordUsing !== null) {
            $this->evaluate($this->fillRecordUsing, ['record' => $record, 'state' => $state], [Model::class => $record, $record::class => $record]);

            return;
        }

        if ($this->hasRelationship) {
            $this->fillRelationship($record, $state);

            return;
        }

        $record->setAttribute($this->name, $state);
    }

    protected function fillRelationship(Model $record, mixed $state): void
    {
        $name = $this->getRelationshipName();
        $relationship = method_exists($record, $name) ? $record->{$name}() : null;

        if ($relationship instanceof BelongsToMany) {
            $this->fillBelongsToMany($relationship, $state);

            return;
        }

        if (! $relationship instanceof BelongsTo) {
            throw new LogicException("ImportColumn [{$this->name}] needs a BelongsTo or BelongsToMany relationship [{$name}] on ".$record::class.'.');
        }

        if ($state === null || $state === []) {
            $relationship->dissociate();

            return;
        }

        $related = $this->resolveRelatedRecord($relationship, $state);

        if ($related === null) {
            throw new RowImportFailedException(__('alfakit::import.failures.relationship_not_found', [
                'attribute' => $this->getLabel(),
                'value' => is_scalar($state) ? (string) $state : json_encode($state),
            ]));
        }

        $relationship->associate($related);
    }

    /**
     * Resolve every value of the cell now (so a missing one fails the row before anything is
     * written); the keys are synced by `saveRelationship()` once the record exists.
     *
     * @param  BelongsToMany<Model, Model>  $relationship
     */
    protected function fillBelongsToMany(BelongsToMany $relationship, mixed $state): void
    {
        $values = $state === null ? [] : (is_array($state) ? array_values($state) : [$state]);
        $keys = [];

        foreach ($values as $value) {
            $related = $this->resolveRelatedRecord($relationship, $value);

            if ($related === null) {
                throw new RowImportFailedException(__('alfakit::import.failures.relationship_not_found', [
                    'attribute' => $this->getLabel(),
                    'value' => is_scalar($value) ? (string) $value : json_encode($value),
                ]));
            }

            $keys[] = $related->getAttribute($relationship->getRelatedKeyName());
        }

        $this->pendingRelatedKeys = array_values(array_unique($keys, SORT_REGULAR));
    }

    /**
     * Sync a BelongsToMany filled for this row. Called by the importer after the save.
     */
    public function saveRelationship(Model $record): void
    {
        $keys = $this->pendingRelatedKeys;
        $this->pendingRelatedKeys = null;

        if ($keys === null) {
            return;
        }

        $relationship = $record->{$this->getRelationshipName()}();

        if ($relationship instanceof BelongsToMany) {
            $relationship->sync($keys);
        }
    }

    /** Forget a BelongsToMany filled for a previous row (e.g. one that failed). */
    public function flushPendingRelationship(): void
    {
        $this->pendingRelatedKeys = null;
    }

    /**
     * @param  BelongsTo<Model, Model>|BelongsToMany<Model, Model>  $relationship
     */
    protected function resolveRelatedRecord(BelongsTo|BelongsToMany $relationship, mixed $state): ?Model
    {
        $resolveUsing = $this->relationshipResolveUsing;

        if ($resolveUsing instanceof Closure) {
            $related = $this->evaluate($resolveUsing, ['state' => $state, 'relationship' => $relationship]);

            return $related instanceof Model ? $related : null;
        }

        // Only records of the current tenant can be linked.
        $query = TenantScope::apply($relationship->getRelated()->newQuery());
        $key = $relationship instanceof BelongsTo ? $relationship->getOwnerKeyName() : $relationship->getRelatedKeyName();
        $attributes = $resolveUsing === null ? [$key] : (array) $resolveUsing;

        return $query
            ->where(function (Builder $query) use ($attributes, $state): void {
                foreach ($attributes as $attribute) {
                    $query->orWhere($query->qualifyColumn($attribute), $state);
                }
            })
            ->first();
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $importer = $this->importer;

        return match ($parameterName) {
            'importer' => [$importer],
            'record' => [$importer?->getRecord()],
            'data' => [$importer?->getData() ?? []],
            'originalData' => [$importer?->getOriginalData() ?? []],
            'options' => [$importer?->getOptions() ?? []],
            'import' => [$importer?->getImport()],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        $record = $this->importer?->getRecord();

        return match (true) {
            $this->importer instanceof $parameterType => [$this->importer],
            $record instanceof $parameterType => [$record],
            default => [],
        };
    }
}
