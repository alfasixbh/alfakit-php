<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\CanAllowHtmlOptions;
use AlfaSix\Alfakit\Forms\Components\Concerns\CanCreateAndEditOptions;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasAffixes;
use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use AlfaSix\Alfakit\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class Select extends Field
{
    use CanAllowHtmlOptions;
    use CanCreateAndEditOptions;
    use HasAffixes;
    use HasOptions {
        getOptions as protected getOptionsFromDefinition;
        getSerializedOptions as protected getSerializedOptionsFromDefinition;
    }

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isSearchable = false;

    protected bool|Closure $isNative = false;

    protected bool|Closure $isPreloaded = false;

    protected bool|Closure $canSelectPlaceholder = true;

    protected int|Closure $optionsLimit = 50;

    protected string|Closure|null $noSearchResultsMessage = null;

    protected string|Closure|null $searchPrompt = null;

    protected string|Closure|null $loadingMessage = null;

    protected string|Closure|null $noOptionsMessage = null;

    protected string|Closure|null $searchingMessage = null;

    protected bool|Closure $shouldWrapOptionLabels = true;

    protected int|Closure $searchDebounce = 300;

    protected int|Closure|null $maxItems = null;

    protected int|Closure|null $minItems = null;

    protected ?Closure $getSearchResultsUsing = null;

    protected ?Closure $getOptionLabelUsing = null;

    protected ?Closure $getOptionLabelsUsing = null;

    // Relationship support
    protected string|Closure|null $relationshipName = null;

    protected string|Closure|null $relationshipTitleAttribute = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    protected bool|Closure $ignoresRecord = false;

    protected bool|Closure $isReorderable = false;

    protected ?Closure $getOptionLabelFromRecordUsing = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $searchColumns = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $pivotData = [];

    protected bool|Closure $isBoolean = false;

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    /**
     * @param  bool|array<string>|Closure  $condition  Pass column names to limit relationship search.
     */
    public function searchable(bool|array|Closure $condition = true): static
    {
        if (is_array($condition)) {
            $this->searchColumns = $condition;
            $condition = true;
        }

        $this->isSearchable = $condition;

        return $this;
    }

    public function native(bool|Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    /**
     * Load all relationship options up front instead of searching on demand.
     */
    public function preload(bool|Closure $condition = true): static
    {
        $this->isPreloaded = $condition;

        return $this;
    }

    public function selectablePlaceholder(bool|Closure $condition = true): static
    {
        $this->canSelectPlaceholder = $condition;

        return $this;
    }

    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    public function noSearchResultsMessage(string|Closure|null $message): static
    {
        $this->noSearchResultsMessage = $message;

        return $this;
    }

    public function searchPrompt(string|Closure|null $message): static
    {
        $this->searchPrompt = $message;

        return $this;
    }

    public function loadingMessage(string|Closure|null $message): static
    {
        $this->loadingMessage = $message;

        return $this;
    }

    /**
     * Shown when the select has no options at all (before any search).
     */
    public function noOptionsMessage(string|Closure|null $message): static
    {
        $this->noOptionsMessage = $message;

        return $this;
    }

    /**
     * Shown while search results are loading.
     */
    public function searchingMessage(string|Closure|null $message): static
    {
        $this->searchingMessage = $message;

        return $this;
    }

    /**
     * `wrapOptionLabels(false)`: long option labels are cut off with an ellipsis instead
     * of wrapping onto several lines.
     */
    public function wrapOptionLabels(bool|Closure $condition = true): static
    {
        $this->shouldWrapOptionLabels = $condition;

        return $this;
    }

    public function searchDebounce(int|Closure $milliseconds): static
    {
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function maxItems(int|Closure|null $count): static
    {
        $this->maxItems = $count;

        return $this;
    }

    public function minItems(int|Closure|null $count): static
    {
        $this->minItems = $count;

        return $this;
    }

    /**
     * Provide search results manually: `fn (string $search): array => [...]`.
     */
    public function getSearchResultsUsing(?Closure $callback): static
    {
        $this->getSearchResultsUsing = $callback;

        return $this;
    }

    /**
     * Resolve the label of a single value: `fn ($value): ?string`.
     */
    public function getOptionLabelUsing(?Closure $callback): static
    {
        $this->getOptionLabelUsing = $callback;

        return $this;
    }

    /**
     * Resolve labels for many values at once: `fn (array $values): array`.
     */
    public function getOptionLabelsUsing(?Closure $callback): static
    {
        $this->getOptionLabelsUsing = $callback;

        return $this;
    }

    /**
     * Inside a Repeater (or Builder) item: disable the options other items already
     * selected. Adds `distinct()` and `live()`, and replaces `disableOptionWhen()`.
     */
    public function disableOptionsWhenSelectedInSiblingRepeaterItems(): static
    {
        $this->distinct();
        $this->live();

        // Item fields are clones: the closure works on the instance it's evaluated for.
        return $this->disableOptionWhen(static function (Select $component): array {
            $item = Repeater::findItemOf($component);

            if ($item === null) {
                return [];
            }

            [$repeater, $index, $relativePath] = $item;
            $values = [];

            foreach ($repeater->getSiblingItemStates($index, $relativePath) as $state) {
                foreach (Arr::wrap($state) as $value) {
                    if (is_scalar($value) && $value !== '') {
                        $values[] = (string) $value;
                    }
                }
            }

            return array_values(array_unique($values));
        });
    }

    public function boolean(?string $trueLabel = null, ?string $falseLabel = null, ?string $placeholder = null): static
    {
        $this->isBoolean = true;

        $this->options([
            1 => $trueLabel ?? __('alfakit::forms.select.boolean.true'),
            0 => $falseLabel ?? __('alfakit::forms.select.boolean.false'),
        ]);

        $this->placeholder($placeholder ?? '-');

        return $this;
    }

    /**
     * Populate options from an Eloquent relationship on the schema's model.
     * `ignoreRecord` leaves the record being edited out of the options (and
     * rejects it on submit), for self-referencing relationships like `parent`.
     */
    public function relationship(string|Closure|null $name = null, string|Closure|null $titleAttribute = null, ?Closure $modifyQueryUsing = null, bool|Closure $ignoreRecord = false): static
    {
        $this->relationshipName = $name ?? fn (): string => (string) str($this->getName())->beforeLast('_id')->camel();
        $this->relationshipTitleAttribute = $titleAttribute;
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;
        $this->ignoresRecord = $ignoreRecord;

        return $this;
    }

    /**
     * Let users reorder the chosen options of a `multiple()` select (drag or
     * keyboard); the state keeps the order they choose.
     */
    public function reorderable(bool|Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    public function isReorderable(): bool
    {
        return $this->isMultiple() && (bool) $this->evaluate($this->isReorderable);
    }

    public function getOptionLabelFromRecordUsing(?Closure $callback): static
    {
        $this->getOptionLabelFromRecordUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>|Closure  $data
     */
    public function pivotData(array|Closure $data): static
    {
        $this->pivotData = $data;

        return $this;
    }

    // ------------------------------------------------------------- Resolution

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isSearchable);
    }

    public function hasRelationship(): bool
    {
        return $this->relationshipName !== null;
    }

    public function getRelationshipName(): ?string
    {
        return $this->evaluate($this->relationshipName);
    }

    public function getRelationship(): ?Relation
    {
        $name = $this->getRelationshipName();
        $model = $this->getModelInstance();

        if ($name === null || $model === null) {
            return null;
        }

        return $model->{$name}();
    }

    public function getRelationshipTitleAttribute(): ?string
    {
        return $this->evaluate($this->relationshipTitleAttribute);
    }

    public function getRelationshipQuery(): ?Builder
    {
        $relationship = $this->getRelationship();

        if ($relationship === null) {
            return null;
        }

        $related = $relationship->getRelated();
        $query = TenantScope::apply($related->newQuery());

        if ((bool) $this->evaluate($this->ignoresRecord) && ($record = $this->getRecord()) !== null && $record->exists && $record->getTable() === $related->getTable()) {
            $query->whereKeyNot($record->getKey());
        }

        if ($this->modifyRelationshipQueryUsing !== null) {
            $query = $this->evaluate($this->modifyRelationshipQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }

    protected function getRelationshipKeyName(): string
    {
        $relationship = $this->getRelationship();

        return match (true) {
            $relationship instanceof BelongsTo => $relationship->getOwnerKeyName(),
            $relationship instanceof BelongsToMany => $relationship->getRelatedKeyName(),
            default => $relationship?->getRelated()->getKeyName() ?? 'id',
        };
    }

    protected function getOptionLabelFromRecord(Model $record): string
    {
        if ($this->getOptionLabelFromRecordUsing !== null) {
            return (string) $this->evaluate($this->getOptionLabelFromRecordUsing, ['record' => $record], [Model::class => $record, $record::class => $record]);
        }

        return (string) $record->getAttribute($this->getRelationshipTitleAttribute() ?? 'name');
    }

    /**
     * @return array<string, string>
     */
    protected function getRelationshipOptions(?string $search = null, bool $applyLimit = true): array
    {
        $query = $this->getRelationshipQuery();

        if ($query === null) {
            return [];
        }

        if (filled($search)) {
            $columns = $this->evaluate($this->searchColumns) ?? [$this->getRelationshipTitleAttribute() ?? 'name'];

            $query->where(function (Builder $query) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        if ($applyLimit) {
            $query->limit($this->evaluate($this->optionsLimit));
        }

        $keyName = $this->getRelationshipKeyName();

        if ($this->getOptionLabelFromRecordUsing === null && $this->getRelationshipTitleAttribute() !== null) {
            $title = $this->getRelationshipTitleAttribute();

            if ($query->getQuery()->orders === null || $query->getQuery()->orders === []) {
                $query->orderBy($title);
            }

            return $query->pluck($title, $keyName)->mapWithKeys(static fn ($label, $key): array => [(string) $key => (string) $label])->all();
        }

        return $query->get()->mapWithKeys(fn (Model $record): array => [(string) $record->getAttribute($keyName) => $this->getOptionLabelFromRecord($record)])->all();
    }

    public function getOptions(): array
    {
        if ($this->hasRelationship() && $this->options === null) {
            if ($this->isSearchable() && ! $this->evaluate($this->isPreloaded)) {
                return [];
            }

            return $this->getRelationshipOptions(applyLimit: $this->isSearchable());
        }

        return $this->getOptionsFromDefinition();
    }

    /**
     * Search results for async/searchable selects.
     *
     * @return array<string, string>
     */
    public function getSearchResults(string $search): array
    {
        return $this->sanitizeOptionLabelMap($this->getUnsanitizedSearchResults($search));
    }

    /**
     * @return array<string, string>
     */
    protected function getUnsanitizedSearchResults(string $search): array
    {
        if ($this->getSearchResultsUsing !== null) {
            return $this->evaluate($this->getSearchResultsUsing, ['search' => $search]) ?? [];
        }

        if ($this->hasRelationship()) {
            return $this->getRelationshipOptions($search);
        }

        $search = mb_strtolower($search);

        return array_filter(
            $this->getFlatOptions(),
            static fn (string $label): bool => str_contains(mb_strtolower($label), $search),
        );
    }

    /**
     * Labels for the given selected values, so the renderer can display them before
     * any search has happened.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public function getOptionLabelsFor(array $values): array
    {
        return $this->sanitizeOptionLabelMap($this->getUnsanitizedOptionLabelsFor($values));
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    protected function getUnsanitizedOptionLabelsFor(array $values): array
    {
        $values = array_values(array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== ''));

        if ($values === []) {
            return [];
        }

        if ($this->getOptionLabelsUsing !== null) {
            return $this->evaluate($this->getOptionLabelsUsing, ['values' => $values]) ?? [];
        }

        if ($this->getOptionLabelUsing !== null) {
            return collect($values)->mapWithKeys(fn (mixed $value): array => [
                (string) $value => (string) $this->evaluate($this->getOptionLabelUsing, ['value' => $value]),
            ])->all();
        }

        if ($this->hasRelationship()) {
            $query = $this->getRelationshipQuery();

            if ($query === null) {
                return [];
            }

            $keyName = $this->getRelationshipKeyName();

            return $query->whereIn($keyName, $values)->get()
                ->mapWithKeys(fn (Model $record): array => [(string) $record->getAttribute($keyName) => $this->getOptionLabelFromRecord($record)])
                ->all();
        }

        $flat = $this->getFlatOptions();

        return collect($values)->mapWithKeys(static fn (mixed $value): array => [(string) $value => $flat[(string) $value] ?? (string) $value])->all();
    }

    public function getSerializedOptions(): array
    {
        return $this->sanitizeSerializedOptionLabels($this->getSerializedOptionsFromDefinition());
    }

    // ------------------------------------------------------------- State

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return $this->isMultiple() ? [] : null;
    }

    public function loadStateFromRelationships(): void
    {
        $relationship = $this->getRelationship();
        $record = $this->getRecord();

        if ($relationship === null || $record === null || ! $record->exists) {
            return;
        }

        if ($relationship instanceof BelongsToMany) {
            $this->state($relationship->pluck($relationship->getQualifiedRelatedKeyName())->map(static fn ($key): string => (string) $key)->all());
        } elseif ($relationship instanceof BelongsTo) {
            $this->state($record->getAttribute($relationship->getForeignKeyName()));
        } elseif ($relationship instanceof HasOne) {
            $this->state($relationship->getResults()?->getKey());
        }
    }

    public function saveRelationships(): void
    {
        if (! $this->shouldSaveRelationships()) {
            return;
        }

        $relationship = $this->getRelationship();
        $record = $this->getRecord();

        if ($relationship === null || $record === null || ! $record->exists) {
            return;
        }

        if ($relationship instanceof BelongsToMany) {
            $state = Arr::wrap($this->getState() ?? []);
            $pivotData = $this->evaluate($this->pivotData) ?? [];

            $relationship->sync($pivotData === [] ? $state : collect($state)->mapWithKeys(static fn ($id): array => [$id => $pivotData])->all());
        }
    }

    public function isDehydrated(): bool
    {
        if ($this->getRelationship() instanceof BelongsToMany) {
            return false; // Persisted through saveRelationships() instead.
        }

        return parent::isDehydrated();
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($this->isMultiple()) {
            return array_values(Arr::wrap($state ?? []));
        }

        if ($state === '' || $state === null) {
            return null;
        }

        if ($this->evaluate($this->isBoolean)) {
            return (bool) $state;
        }

        return $state;
    }

    protected function getTypeValidationRules(): array
    {
        $rules = [];

        if ($this->isMultiple()) {
            $rules[] = 'array';

            if (($min = $this->evaluate($this->minItems)) !== null) {
                $rules[] = "min:{$min}";
            }

            if (($max = $this->evaluate($this->maxItems)) !== null) {
                $rules[] = "max:{$max}";
            }

            return $rules; // Per-item rules are emitted by getValidationRulesByPath().
        }

        $itemRule = $this->getOptionValidationRule();

        if ($itemRule !== null) {
            $rules[] = $itemRule;
        }

        return $rules;
    }

    /**
     * The rule asserting a single value is a valid option (`exists` for relationships,
     * `in` for static options). Null when options are dynamic.
     */
    protected function getOptionValidationRule(): Closure|In|null
    {
        if ($this->hasRelationship()) {
            $query = $this->getRelationshipQuery();

            if ($query === null) {
                return null;
            }

            // Check against the same query that lists the options, so the tenant scope and
            // `modifyQueryUsing` constraints apply to submitted values too.
            $keyName = $this->getRelationshipKeyName();

            return static function (string $attribute, mixed $value, Closure $fail) use ($query, $keyName): void {
                if (! is_scalar($value) || ! (clone $query)->where($query->qualifyColumn($keyName), $value)->exists()) {
                    $fail(__('validation.exists'));
                }
            };
        }

        // Options from an API or another custom source: a submitted value is valid when the
        // field's own label lookup recognises it, so a forged value can't slip through.
        if ($this->getOptionLabelsUsing !== null || $this->getOptionLabelUsing !== null) {
            return function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail(__('validation.in'));

                    return;
                }

                $label = $this->getUnsanitizedOptionLabelsFor([$value])[(string) $value] ?? null;

                if ($label === null || $label === '') {
                    $fail(__('validation.in'));
                }
            };
        }

        if ($this->getSearchResultsUsing !== null || $this->evaluate($this->isBoolean)) {
            return null;
        }

        // Disabled options are refused like unknown ones.
        $flat = $this->getFlatOptions();

        return $flat === [] ? null : Rule::in($this->getEnabledOptionValues($flat));
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        if ($rules === [] || ! $this->isMultiple()) {
            return $rules;
        }

        $itemRule = $this->getOptionValidationRule();

        if ($itemRule !== null) {
            $rules[$this->getStatePath().'.*'] = [$itemRule];
        }

        return $rules;
    }

    protected function getSchemaProps(): array
    {
        $state = $this->getState();
        $selected = $this->isMultiple() ? Arr::wrap($state ?? []) : ($state === null ? [] : [$state]);

        return [
            'options' => $this->getSerializedOptions(),
            'selectedLabels' => $this->isSearchable() ? $this->getOptionLabelsFor($selected) : null,
            'multiple' => $this->isMultiple() ?: null,
            'searchable' => $this->isSearchable() ?: null,
            'native' => $this->evaluate($this->isNative) ?: null,
            'selectablePlaceholder' => (bool) $this->evaluate($this->canSelectPlaceholder),
            'searchDebounce' => $this->isSearchable() ? $this->evaluate($this->searchDebounce) : null,
            'noSearchResultsMessage' => $this->evaluate($this->noSearchResultsMessage),
            'searchPrompt' => $this->evaluate($this->searchPrompt),
            'loadingMessage' => $this->evaluate($this->loadingMessage),
            'noOptionsMessage' => $this->evaluate($this->noOptionsMessage),
            'searchingMessage' => $this->evaluate($this->searchingMessage),
            'allowHtml' => $this->isHtmlAllowed() ?: null,
            'wrapOptionLabels' => $this->evaluate($this->shouldWrapOptionLabels) ? null : false,
            'maxItems' => $this->evaluate($this->maxItems),
            'minItems' => $this->evaluate($this->minItems),
            'reorderable' => $this->isReorderable() ?: null,
            ...$this->getAffixes(),
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
