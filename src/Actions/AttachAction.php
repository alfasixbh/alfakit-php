<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Attach existing records to a BelongsToMany relationship (relation managers).
 */
class AttachAction extends Action
{
    protected ?RelationManager $relationManager = null;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isPreloaded = false;

    protected ?Closure $modifyRecordSelectOptionsQueryUsing = null;

    protected ?Closure $modifyRecordSelectUsing = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $recordSelectSearchColumns = null;

    /** @var class-string|Closure|null */
    protected string|Closure|null $tableSelect = null;

    protected bool|Closure $allowsDuplicates = false;

    /** @var array<\AlfaSix\Alfakit\Schemas\Components\Component>|Closure */
    protected array|Closure $extraFormComponents = [];

    public static function make(string $name = 'attach'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.attach.label'));
        $this->color(Color::Gray);
        $this->icon('alfakit::actions.attach');
        $this->button();
        $this->modalHeading(fn (): string => __('alfakit::actions.attach.modal.heading', ['label' => $this->relationManager?->getModelLabel() ?? '']));
        $this->modalSubmitActionLabel(__('alfakit::actions.attach.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.attach.notification'));

        $this->form(fn (): array => [
            $this->getRecordSelect(),
            ...($this->evaluate($this->extraFormComponents) ?? []),
        ]);

        $this->action(function (array $data): void {
            $relationship = $this->getBelongsToMany();
            $titleAttribute = $this->relationManager::getRecordTitleAttribute() ?? 'name';
            $ids = array_values(array_filter((array) ($data['recordId'] ?? []), 'is_scalar'));
            $pivot = \Illuminate\Support\Arr::except($data, ['recordId']);

            // Only records the select could have offered: not yet attached (unless
            // `allowDuplicates()`), in this tenant, and allowed by `recordSelectOptionsQuery()`.
            $allowed = $this->getOptionsQuery($relationship, $titleAttribute)
                ->whereIn($relationship->getRelated()->getQualifiedKeyName(), $ids)
                ->pluck($relationship->getRelated()->getQualifiedKeyName())
                ->all();

            abort_if(count($allowed) !== count(array_unique($ids)), 422);

            $relationship->attach($allowed, $pivot);
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }

    public function relationManager(RelationManager $manager): static
    {
        $this->relationManager = $manager;

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function preloadRecordSelect(bool|Closure $condition = true): static
    {
        $this->isPreloaded = $condition;

        return $this;
    }

    public function recordSelectOptionsQuery(?Closure $callback): static
    {
        $this->modifyRecordSelectOptionsQueryUsing = $callback;

        return $this;
    }

    /**
     * Columns the record select searches (default: the record title attribute).
     *
     * @param  array<int, string>|Closure|null  $columns
     */
    public function recordSelectSearchColumns(array|Closure|null $columns): static
    {
        $this->recordSelectSearchColumns = $columns;

        return $this;
    }

    /**
     * Customise the record select: `fn (Select $select) => $select->placeholder('…')`.
     */
    public function recordSelect(?Closure $callback): static
    {
        $this->modifyRecordSelectUsing = $callback;

        return $this;
    }

    /**
     * Pick the records in a modal table (a `ModalTableSelect`) instead of a searchable
     * select: a table class with a static `configure(Table $table)` method, a resource class
     * or a closure receiving `$table`. The table lists the records the select would offer
     * (not yet attached, in this tenant, `recordSelectOptionsQuery()`).
     *
     * @param  class-string|Closure|null  $configuration
     */
    public function tableSelect(string|Closure|null $configuration): static
    {
        $this->tableSelect = $configuration;

        return $this;
    }

    public function hasTableSelect(): bool
    {
        return $this->tableSelect !== null;
    }

    /**
     * Offer records that are already attached, so one record can be attached several times
     * (the pivot table needs its own primary key for that).
     */
    public function allowDuplicates(bool|Closure $condition = true): static
    {
        $this->allowsDuplicates = $condition;

        return $this;
    }

    public function allowsDuplicates(): bool
    {
        return (bool) $this->evaluate($this->allowsDuplicates) || ($this->relationManager?->allowsDuplicates() ?? false);
    }

    /**
     * Extra pivot fields.
     *
     * @param  array<\AlfaSix\Alfakit\Schemas\Components\Component>|Closure  $components
     */
    public function form(array|Closure|null $components): static
    {
        if ($this->formComponents === null) {
            return parent::form($components);
        }

        $this->extraFormComponents = $components ?? [];

        return $this;
    }

    protected function getBelongsToMany(): BelongsToMany
    {
        $relationship = $this->relationManager?->getRelationship();

        if (! $relationship instanceof BelongsToMany) {
            throw new RuntimeException('AttachAction can only be used with BelongsToMany relationships inside a relation manager.');
        }

        return $relationship;
    }

    /**
     * The record select of the modal form, for a custom schema:
     * `->schema(fn (AttachAction $action): array => [$action->getRecordSelect(), TextInput::make('role')])`.
     */
    public function getRecordSelect(): Select
    {
        $relationship = $this->getBelongsToMany();
        $titleAttribute = $this->relationManager::getRecordTitleAttribute() ?? 'name';

        if ($this->tableSelect !== null) {
            return $this->modifyRecordSelect($this->getRecordTableSelect($relationship, $titleAttribute));
        }

        $searchColumns = $this->getRecordSelectSearchColumns($titleAttribute);

        $select = Select::make('recordId')
            ->label(Str::ucfirst($this->relationManager->getModelLabel()))
            ->required()
            ->multiple($this->isMultiple)
            ->searchable()
            ->preload($this->isPreloaded)
            ->getSearchResultsUsing(function (string $search) use ($relationship, $titleAttribute, $searchColumns): array {
                return $this->getOptionsQuery($relationship, $titleAttribute)
                    ->where(function (Builder $query) use ($searchColumns, $search): void {
                        foreach ($searchColumns as $column) {
                            $query->orWhere($query->qualifyColumn($column), 'like', "%{$search}%");
                        }
                    })
                    ->limit(50)
                    ->pluck($titleAttribute, $relationship->getRelatedKeyName())
                    ->all();
            })
            ->getOptionLabelsUsing(fn (array $values): array => \AlfaSix\Alfakit\Support\TenantScope::apply($relationship->getRelated()->newQuery())->whereIn($relationship->getRelatedKeyName(), $values)->pluck($titleAttribute, $relationship->getRelatedKeyName())->all())
            ->options(fn (): array => $this->evaluate($this->isPreloaded) ? $this->getOptionsQuery($relationship, $titleAttribute)->limit(50)->pluck($titleAttribute, $relationship->getRelatedKeyName())->all() : []);

        return $this->modifyRecordSelect($select);
    }

    protected function modifyRecordSelect(Select $select): Select
    {
        if ($this->modifyRecordSelectUsing !== null) {
            $select = $this->evaluate($this->modifyRecordSelectUsing, ['select' => $select, 'component' => $select], [Select::class => $select, $select::class => $select]) ?? $select;
        }

        return $select;
    }

    /**
     * The `tableSelect()` picker: the configured table over the attachable records.
     */
    protected function getRecordTableSelect(BelongsToMany $relationship, string $titleAttribute): ModalTableSelect
    {
        $configuration = $this->tableSelect;

        $select = ModalTableSelect::make('recordId')
            ->label(Str::ucfirst($this->relationManager->getModelLabel()))
            ->required()
            ->multiple($this->isMultiple)
            ->recordTitleAttribute($titleAttribute);

        // The attachable records, whatever the configuration's own query: listed and
        // accepted only if the select would offer them.
        return $select->tableConfiguration(fn (Table $table): Table => $select
            ->applyTableConfiguration($table->query($this->getOptionsQuery($relationship, $titleAttribute)), $configuration)
            ->query($this->getOptionsQuery($relationship, $titleAttribute)));
    }

    /**
     * @return array<int, string>
     */
    protected function getRecordSelectSearchColumns(string $titleAttribute): array
    {
        $columns = $this->evaluate($this->recordSelectSearchColumns);

        return is_array($columns) && $columns !== [] ? array_values(array_map('strval', $columns)) : [$titleAttribute];
    }

    protected function getOptionsQuery(BelongsToMany $relationship, string $titleAttribute): Builder
    {
        $query = \AlfaSix\Alfakit\Support\TenantScope::apply($relationship->getRelated()->newQuery())
            ->orderBy($titleAttribute);

        if (! $this->allowsDuplicates()) {
            $query->whereNotIn($relationship->getRelated()->getQualifiedKeyName(), $relationship->getQuery()->select($relationship->getQualifiedRelatedKeyName()));
        }

        if ($this->modifyRecordSelectOptionsQueryUsing !== null) {
            $query = $this->evaluate($this->modifyRecordSelectOptionsQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }
}
