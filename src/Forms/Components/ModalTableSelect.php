<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\TenantScope;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\In;
use LogicException;

/**
 * Select one or more records from a table shown in a modal, with the table's search,
 * filters, sorting and pagination. Configure the table with `tableConfiguration()`: a
 * class with a static `configure(Table $table): Table` method, a resource class (its
 * `table()` is reused), or a closure receiving `$table`.
 *
 * Records come from the `relationship()` query (or the table's own query without one),
 * always limited to the current tenant, and submitted keys are validated against it.
 */
class ModalTableSelect extends Select
{
    /** Query-string prefix of the table state the modal sends (`table_search`, …). */
    public const TABLE_STATE_IDENTIFIER = 'table';

    /** @var class-string|Closure|null */
    protected string|Closure|null $tableConfiguration = null;

    protected string|Closure|null $modalHeading = null;

    protected string|Closure|null $selectActionLabel = null;

    protected ?Closure $modifySelectActionUsing = null;

    /** @var array<string, mixed>|Closure|null */
    protected array|Closure|null $tableArguments = null;

    /**
     * @param  class-string|Closure|null  $configuration
     */
    public function tableConfiguration(string|Closure|null $configuration): static
    {
        $this->tableConfiguration = $configuration;

        return $this;
    }

    /**
     * The attribute used as a selected record's label when there is no relationship.
     */
    public function recordTitleAttribute(string|Closure|null $attribute): static
    {
        $this->relationshipTitleAttribute = $attribute;

        return $this;
    }

    public function modalHeading(string|Closure|null $heading): static
    {
        $this->modalHeading = $heading;

        return $this;
    }

    public function selectActionLabel(string|Closure|null $label): static
    {
        $this->selectActionLabel = $label;

        return $this;
    }

    /**
     * Customise the button that opens the picker and its modal:
     * `fn (Action $action) => $action->label('Pick')->modalHeading('Search')->modalSubmitActionLabel('Use')`.
     * Label, icon, modal heading, description, width and submit label are used.
     */
    public function selectAction(?Closure $callback): static
    {
        $this->modifySelectActionUsing = $callback;

        return $this;
    }

    /**
     * The select button and modal, with `selectActionLabel()`, `modalHeading()` and the
     * `selectAction()` customisation applied.
     */
    public function getSelectAction(): Action
    {
        $action = Action::make('select')
            ->label($this->evaluate($this->selectActionLabel) ?? __('alfakit::forms.modal_table_select.select'))
            ->modalHeading($this->evaluate($this->modalHeading) ?? __('alfakit::forms.modal_table_select.heading', ['label' => Labels::forSentence($this->getLabel() ?? '')]))
            ->modalSubmitActionLabel(__('alfakit::forms.modal_table_select.select'))
            ->modalWidth(Width::ThreeExtraLarge);

        if ($this->modifySelectActionUsing !== null) {
            $action = $this->evaluate($this->modifySelectActionUsing, ['action' => $action], [Action::class => $action]) ?? $action;
        }

        return $action;
    }

    /**
     * Values from the form passed to the table configuration, e.g.
     * `fn (Get $get): array => ['category_id' => $get('category_id')]`. A configuration
     * closure receives them as `$arguments`. They only change what the picker shows: the
     * submitted keys are still validated against the `relationship()` query.
     *
     * @param  array<string, mixed>|Closure|null  $arguments
     */
    public function tableArguments(array|Closure|null $arguments): static
    {
        $this->tableArguments = $arguments;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTableArguments(): array
    {
        $arguments = $this->evaluate($this->tableArguments);

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * The picker table, with `$state` applied. Actions, record links, reordering and
     * polling are switched off: selecting records is the only interaction.
     */
    public function getTable(?TableState $state = null): Table
    {
        $table = Table::make($this);
        $configuration = $this->tableConfiguration;
        $arguments = $this->getTableArguments();

        // `$table->getArguments()` in the configuration, where the table supports it.
        if (method_exists($table, 'arguments')) {
            $table->arguments($arguments);
        }

        $table = $this->applyTableConfiguration($table, $configuration);

        if ($this->hasRelationship()) {
            // The relationship query is tenant scoped; a `modifyQueryUsing()` from the
            // table configuration still narrows it.
            $query = $this->getRelationshipQuery();

            if ($query === null) {
                throw new LogicException("ModalTableSelect [{$this->getName()}] could not resolve its relationship.");
            }

            $table->query($query);
        } else {
            $query = $table->getQuery();

            if ($query === null) {
                throw new LogicException("ModalTableSelect [{$this->getName()}] needs a relationship() or a table query.");
            }

            $table->query(TenantScope::apply($query))->modifyQueryUsing(null);
        }

        $table
            ->recordActions([])
            ->headerActions([])
            ->bulkActions([])
            ->emptyStateActions([])
            ->recordUrl(null)
            ->recordAction(null)
            ->reorderable(null, false)
            ->poll(null)
            ->deferLoading(false);

        if ($state !== null) {
            $table->state($state);
        }

        return $table;
    }

    /**
     * Configure `$table` from a table class (static `configure(Table $table)`), a resource
     * class (its `table()`) or a closure receiving `$table` and `$arguments`.
     *
     * @param  class-string|Closure|null  $configuration
     */
    public function applyTableConfiguration(Table $table, string|Closure|null $configuration): Table
    {
        if (is_string($configuration)) {
            if (method_exists($configuration, 'configure')) {
                return $configuration::configure($table) ?? $table;
            }

            if (is_subclass_of($configuration, Resource::class)) {
                // Without a relationship, the resource's model is what the picker lists.
                return $configuration::table($table->model($configuration::getModel()));
            }

            throw new LogicException("Table configuration [{$configuration}] must be a resource or define a static configure(Table \$table) method.");
        }

        if ($configuration instanceof Closure) {
            return $this->evaluate($configuration, ['table' => $table, 'arguments' => $this->getTableArguments()], [Table::class => $table]) ?? $table;
        }

        return $table;
    }

    /**
     * The picker's filters form, for the options of searchable select filters.
     */
    public function getFiltersSchema(): Schema
    {
        return $this->getTable()->getFiltersSchema();
    }

    /**
     * The query listing the records that may be selected (before the modal's search and
     * filters): what the picker table lists, so submitted keys are checked against it.
     *
     * @return Builder<Model>|null
     */
    public function getSelectableQuery(): ?Builder
    {
        return $this->getTable()->getQuery();
    }

    protected function getSelectableKeyName(): string
    {
        return $this->hasRelationship() ? $this->getRelationshipKeyName() : ($this->getTable()->getModelInstance()?->getKeyName() ?? 'id');
    }

    /**
     * The table payload and the labels of the records on the current page, for the modal.
     *
     * @return array{table: array<string, mixed>, labels: array<string, string>}
     */
    public function getTablePayload(TableState $state): array
    {
        $table = $this->getTable($state);
        $schema = $table->toSchema();
        $keyName = $this->getSelectableKeyName();
        $labels = [];

        foreach ($table->getRecords() as $record) {
            $labels[(string) $record->getAttribute($keyName)] = $this->getOptionLabelFromRecord($record);
        }

        // Cells are display-only in the picker: editable columns (toggles, inputs) render
        // disabled, and links would navigate away from the form.
        foreach ($schema['records'] ?? [] as $index => $row) {
            foreach ($row['cells'] ?? [] as $name => $cell) {
                $schema['records'][$index]['cells'][$name] = [...Arr::except($cell, ['url', 'action']), 'disabled' => true];
            }
        }

        return ['table' => $schema, 'labels' => $labels];
    }

    public function getOptions(): array
    {
        return []; // Records are listed by the modal table, never inlined.
    }

    public function getSearchResults(string $search): array
    {
        return []; // Searching happens in the modal table.
    }

    public function getOptionLabelsFor(array $values): array
    {
        if ($this->hasRelationship() || $this->getOptionLabelsUsing !== null || $this->getOptionLabelUsing !== null) {
            return parent::getOptionLabelsFor($values);
        }

        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_scalar($value) && $value !== ''));
        $query = $values === [] ? null : $this->getSelectableQuery();

        if ($query === null) {
            return [];
        }

        $keyName = $this->getSelectableKeyName();

        return $query->whereIn($query->qualifyColumn($keyName), $values)->get()
            ->mapWithKeys(fn (Model $record): array => [(string) $record->getAttribute($keyName) => $this->getOptionLabelFromRecord($record)])
            ->all();
    }

    protected function getOptionValidationRule(): Closure|In|null
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $query = $this->getSelectableQuery();

            if (! is_scalar($value) || $query === null || ! $query->where($query->qualifyColumn($this->getSelectableKeyName()), $value)->exists()) {
                $fail(__('validation.exists'));
            }
        };
    }

    protected function getSchemaProps(): array
    {
        $state = $this->getState();
        $selected = $this->isMultiple() ? (is_array($state) ? $state : []) : ($state === null || $state === '' ? [] : [$state]);

        return [
            'multiple' => $this->isMultiple() ?: null,
            'selectedLabels' => $this->getOptionLabelsFor($selected),
            'maxItems' => $this->evaluate($this->maxItems),
            'minItems' => $this->evaluate($this->minItems),
            ...$this->getSelectActionSchema(),
            'tableStateIdentifier' => self::TABLE_STATE_IDENTIFIER,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSelectActionSchema(): array
    {
        $action = $this->getSelectAction();
        $width = $action->getModalWidth();

        return [
            'modalHeading' => (string) ($action->getModalHeading() ?? $action->getLabel()),
            'modalDescription' => $action->getModalDescription(),
            'modalSubmitActionLabel' => $action->getModalSubmitActionLabel(),
            'modalWidth' => $width === Width::ThreeExtraLarge->value ? null : $width,
            'selectActionLabel' => $action->getLabel(),
            'selectActionIcon' => $action->getIcon(),
        ];
    }
}
