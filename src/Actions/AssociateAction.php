<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Support\Enums\Color;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Associate existing records with a HasMany/MorphMany relationship (relation managers).
 */
class AssociateAction extends Action
{
    protected ?RelationManager $relationManager = null;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isPreloaded = false;

    protected ?Closure $modifyRecordSelectOptionsQueryUsing = null;

    protected ?Closure $modifyRecordSelectUsing = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $recordSelectSearchColumns = null;

    public static function make(string $name = 'associate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.associate.label'));
        $this->color(Color::Gray);
        $this->icon('alfakit::actions.associate');
        $this->button();
        $this->modalHeading(fn (): string => __('alfakit::actions.associate.modal.heading', ['label' => $this->relationManager?->getModelLabel() ?? '']));
        $this->modalSubmitActionLabel(__('alfakit::actions.associate.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.associate.notification'));

        $this->form(fn (): array => [$this->getRecordSelect()]);

        $this->action(function (array $data): void {
            $relationship = $this->getRelationship();
            $related = $relationship->getRelated();

            $ids = array_values(array_filter((array) ($data['recordId'] ?? []), 'is_scalar'));

            // Only unowned records the select could have offered (same tenant, allowed by
            // `recordSelectOptionsQuery()`).
            $records = $this->getOptionsQuery()->whereKey($ids)->get();

            abort_if($records->count() !== count(array_unique($ids)), 422);

            $records->each(function ($record) use ($relationship): void {
                $relationship->save($record);
            });
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

    /**
     * Narrow the records that can be associated: `fn (Builder $query) => $query->where(…)`.
     */
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

    protected function getRelationship(): HasMany|MorphMany
    {
        $relationship = $this->relationManager?->getRelationship();

        if (! $relationship instanceof HasMany && ! $relationship instanceof MorphMany) {
            throw new RuntimeException('AssociateAction can only be used with HasMany or MorphMany relationships inside a relation manager.');
        }

        return $relationship;
    }

    /**
     * The record select of the modal form, for a custom schema:
     * `->schema(fn (AssociateAction $action): array => [$action->getRecordSelect(), …])`.
     */
    public function getRecordSelect(): Select
    {
        $relationship = $this->getRelationship();
        $titleAttribute = $this->relationManager::getRecordTitleAttribute() ?? 'name';
        $related = $relationship->getRelated();
        $columns = $this->evaluate($this->recordSelectSearchColumns);
        $searchColumns = is_array($columns) && $columns !== [] ? array_values(array_map('strval', $columns)) : [$titleAttribute];

        $select = Select::make('recordId')
            ->label(Str::ucfirst($this->relationManager->getModelLabel()))
            ->required()
            ->multiple($this->isMultiple)
            ->searchable()
            ->preload($this->isPreloaded)
            ->getSearchResultsUsing(fn (string $search): array => $this->getOptionsQuery()
                ->where(function (Builder $query) use ($searchColumns, $search): void {
                    foreach ($searchColumns as $column) {
                        $query->orWhere($query->qualifyColumn($column), 'like', "%{$search}%");
                    }
                })
                ->limit(50)
                ->pluck($titleAttribute, $related->getKeyName())
                ->all())
            ->getOptionLabelsUsing(fn (array $values): array => \AlfaSix\Alfakit\Support\TenantScope::apply($related->newQuery())->whereKey($values)->pluck($titleAttribute, $related->getKeyName())->all())
            ->options(fn (): array => $this->evaluate($this->isPreloaded) ? $this->getOptionsQuery()->orderBy($titleAttribute)->limit(50)->pluck($titleAttribute, $related->getKeyName())->all() : []);

        if ($this->modifyRecordSelectUsing !== null) {
            $select = $this->evaluate($this->modifyRecordSelectUsing, ['select' => $select, 'component' => $select], [Select::class => $select]) ?? $select;
        }

        return $select;
    }

    /**
     * Records that can be associated: not owned by any parent yet, in the current tenant.
     *
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected function getOptionsQuery(): Builder
    {
        $relationship = $this->getRelationship();

        $query = \AlfaSix\Alfakit\Support\TenantScope::apply($relationship->getRelated()->newQuery())
            ->whereNull($relationship->getQualifiedForeignKeyName());

        if ($this->modifyRecordSelectOptionsQueryUsing !== null) {
            $query = $this->evaluate($this->modifyRecordSelectOptionsQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }
}
