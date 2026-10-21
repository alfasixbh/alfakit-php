<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Concerns\CanAllowHtmlOptions;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasOptionsGrid;
use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CheckboxList extends Field
{
    use CanAllowHtmlOptions;
    use HasOptions {
        getOptions as protected getOptionsFromDefinition;
        getSerializedOptions as protected getSerializedOptionsFromDefinition;
    }
    use HasOptionsGrid;

    protected bool|Closure $isSearchable = false;

    protected bool|Closure $isBulkToggleable = false;

    protected bool|Closure $isInline = false;

    protected ?Closure $getOptionDescriptionFromRecordUsing = null;

    protected ?Closure $getOptionLabelFromRecordUsing = null;

    protected string|Closure|null $noSearchResultsMessage = null;

    protected string|Closure|null $searchPrompt = null;

    protected int|Closure|null $searchDebounce = null;

    protected ?Closure $modifySelectAllActionUsing = null;

    protected ?Closure $modifyDeselectAllActionUsing = null;

    protected string|Closure|null $relationshipName = null;

    protected string|Closure|null $relationshipTitleAttribute = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $pivotData = [];

    /** @var array<string, mixed> */
    protected array $relationshipOptionDescriptions = [];

    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function bulkToggleable(bool|Closure $condition = true): static
    {
        $this->isBulkToggleable = $condition;

        return $this;
    }

    public function inline(bool|Closure $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    /**
     * Text shown when the search matches no option.
     */
    public function noSearchResultsMessage(string|Closure|null $message): static
    {
        $this->noSearchResultsMessage = $message;

        return $this;
    }

    /**
     * Placeholder of the search input.
     */
    public function searchPrompt(string|Closure|null $message): static
    {
        $this->searchPrompt = $message;

        return $this;
    }

    /**
     * Milliseconds to wait after typing before the list is filtered (default: immediately).
     */
    public function searchDebounce(int|Closure|null $milliseconds): static
    {
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    /**
     * Option labels for relationship options, from each related record
     * (`fn (Model $record): string => "{$record->name} ({$record->code})"`).
     */
    public function getOptionLabelFromRecordUsing(?Closure $callback): static
    {
        $this->getOptionLabelFromRecordUsing = $callback;

        return $this;
    }

    /**
     * Option descriptions for relationship options, from each related record.
     */
    public function getOptionDescriptionFromRecordUsing(?Closure $callback): static
    {
        $this->getOptionDescriptionFromRecordUsing = $callback;

        return $this;
    }

    /**
     * Customise the bulk toggle "Select all" link: the closure receives an `Action` named
     * `selectAll` (`fn (Action $action) => $action->label('Select every topic')->icon('check')`).
     * Its label, icon and visibility are used; the toggle itself runs in the browser.
     */
    public function selectAllAction(?Closure $callback): static
    {
        $this->modifySelectAllActionUsing = $callback;

        return $this;
    }

    public function deselectAllAction(?Closure $callback): static
    {
        $this->modifyDeselectAllActionUsing = $callback;

        return $this;
    }

    public function getSelectAllAction(): Action
    {
        return $this->makeBulkToggleAction('selectAll', __('alfakit::ui.table.select_all'), $this->modifySelectAllActionUsing);
    }

    public function getDeselectAllAction(): Action
    {
        return $this->makeBulkToggleAction('deselectAll', __('alfakit::ui.table.deselect_all'), $this->modifyDeselectAllActionUsing);
    }

    protected function makeBulkToggleAction(string $name, string $label, ?Closure $modifyUsing): Action
    {
        $action = Action::make($name)->label($label)->link();

        if ($modifyUsing !== null) {
            $action = $this->evaluate($modifyUsing, ['action' => $action], [Action::class => $action]) ?? $action;
        }

        return $action;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeBulkToggleAction(?Closure $modifyUsing, Action $action): ?array
    {
        if ($modifyUsing === null) {
            return null;
        }

        return array_filter([
            'label' => $action->getLabel(),
            'icon' => $action->getIcon(),
            'hidden' => $action->isVisible() ? null : true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function relationship(string|Closure $name, string|Closure $titleAttribute = 'name', ?Closure $modifyQueryUsing = null): static
    {
        $this->relationshipName = $name;
        $this->relationshipTitleAttribute = $titleAttribute;
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;

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

    public function hasRelationship(): bool
    {
        return $this->relationshipName !== null;
    }

    public function getRelationshipName(): ?string
    {
        $name = $this->evaluate($this->relationshipName);

        return is_string($name) && $name !== '' ? $name : null;
    }

    protected function getRelationship(): ?BelongsToMany
    {
        $name = $this->evaluate($this->relationshipName);
        $model = $this->getModelInstance();

        if ($name === null || $model === null) {
            return null;
        }

        $relationship = $model->{$name}();

        return $relationship instanceof BelongsToMany ? $relationship : null;
    }

    public function getOptions(): array
    {
        $relationship = $this->getRelationship();

        if ($relationship === null) {
            return $this->getOptionsFromDefinition();
        }

        $query = \AlfaSix\Alfakit\Support\TenantScope::apply($relationship->getRelated()->newQuery());

        if ($this->modifyRelationshipQueryUsing !== null) {
            $query = $this->evaluate($this->modifyRelationshipQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        $title = $this->evaluate($this->relationshipTitleAttribute);
        $keyName = $relationship->getRelatedKeyName();

        $this->relationshipOptionDescriptions = [];

        if ($this->getOptionDescriptionFromRecordUsing === null && $this->getOptionLabelFromRecordUsing === null) {
            return $query->orderBy($title)->pluck($title, $keyName)
                ->mapWithKeys(static fn ($label, $key): array => [(string) $key => (string) $label])
                ->all();
        }

        $options = [];

        foreach ($query->orderBy($title)->get() as $record) {
            $key = (string) $record->getAttribute($keyName);
            $options[$key] = $this->getOptionLabelFromRecordUsing === null
                ? (string) $record->getAttribute($title)
                : (string) $this->evaluate(
                    $this->getOptionLabelFromRecordUsing,
                    ['record' => $record],
                    [Model::class => $record, $record::class => $record],
                );

            if ($this->getOptionDescriptionFromRecordUsing !== null) {
                $this->relationshipOptionDescriptions[$key] = $this->evaluate(
                    $this->getOptionDescriptionFromRecordUsing,
                    ['record' => $record],
                    [Model::class => $record, $record::class => $record],
                );
            }
        }

        return $options;
    }

    public function getSerializedOptions(): array
    {
        // `getOptions()` collects the relationship descriptions while it loads the records.
        $options = $this->getSerializedOptionsFromDefinition();

        if ($this->relationshipOptionDescriptions !== []) {
            $options = array_map(function (array $option): array {
                $description = $this->relationshipOptionDescriptions[$option['value'] ?? ''] ?? null;

                if (! isset($option['description']) && filled($description)) {
                    $option['description'] = (string) $description;
                }

                return $option;
            }, $options);
        }

        return $this->sanitizeSerializedOptionLabels($options);
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return [];
    }

    public function loadStateFromRelationships(): void
    {
        $relationship = $this->getRelationship();
        $record = $this->getRecord();

        if ($relationship === null || $record === null || ! $record->exists) {
            return;
        }

        $this->state($relationship->pluck($relationship->getQualifiedRelatedKeyName())->map(static fn ($key): string => (string) $key)->all());
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

        $state = Arr::wrap($this->getState() ?? []);
        $pivotData = $this->evaluate($this->pivotData) ?? [];

        $relationship->sync($pivotData === [] ? $state : collect($state)->mapWithKeys(static fn ($id): array => [$id => $pivotData])->all());
    }

    public function isDehydrated(): bool
    {
        return $this->getRelationship() === null && parent::isDehydrated();
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        return array_values(Arr::wrap($state ?? []));
    }

    protected function getTypeValidationRules(): array
    {
        return ['array'];
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        if ($rules === []) {
            return $rules;
        }

        // Disabled options are refused like unknown ones.
        $flat = $this->getFlatOptions();

        if ($flat !== []) {
            $rules[$this->getStatePath().'.*'] = [Rule::in($this->getEnabledOptionValues($flat))];
        }

        return $rules;
    }

    /**
     * Options built from an enum show its `HasDescription` descriptions.
     */
    protected function getEnumOptionDetails(): array
    {
        return ['description'];
    }

    protected function getSchemaProps(): array
    {
        return [
            'options' => $this->getSerializedOptions(),
            'searchable' => $this->evaluate($this->isSearchable) ?: null,
            'noSearchResultsMessage' => $this->evaluate($this->noSearchResultsMessage),
            'searchPrompt' => $this->evaluate($this->searchPrompt),
            'searchDebounce' => $this->evaluate($this->searchDebounce),
            'bulkToggleable' => $this->evaluate($this->isBulkToggleable) ?: null,
            'inline' => $this->evaluate($this->isInline) ?: null,
            'gridColumns' => $this->getGridColumns(),
            'gridDirection' => $this->getGridDirection(),
            'allowHtml' => $this->isHtmlAllowed() ?: null,
            'selectAllAction' => $this->serializeBulkToggleAction($this->modifySelectAllActionUsing, $this->getSelectAllAction()),
            'deselectAllAction' => $this->serializeBulkToggleAction($this->modifyDeselectAllActionUsing, $this->getDeselectAllAction()),
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
