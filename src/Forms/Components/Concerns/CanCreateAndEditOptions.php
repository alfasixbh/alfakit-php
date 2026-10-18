<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

/**
 * `createOptionForm()` / `editOptionForm()` for selects: suffix buttons open a modal form
 * that creates (or updates) the related record on the server, then select it. Both run
 * through the action endpoint as field actions, so the related model's `create` /
 * `update` policy, the relationship query (tenant scope, `modifyQueryUsing`) and the
 * field's visibility are enforced there.
 */
trait CanCreateAndEditOptions
{
    /** @var array<Component>|Closure|null */
    protected array|Closure|null $createOptionForm = null;

    protected ?Closure $createOptionUsing = null;

    protected ?Closure $modifyCreateOptionActionUsing = null;

    protected string|Closure|null $createOptionModalHeading = null;

    /** @var array<Component>|Closure|null */
    protected array|Closure|null $editOptionForm = null;

    protected ?Closure $updateOptionUsing = null;

    protected ?Closure $modifyEditOptionActionUsing = null;

    protected string|Closure|null $editOptionModalHeading = null;

    /**
     * @param  array<Component>|Closure|null  $schema
     */
    public function createOptionForm(array|Closure|null $schema): static
    {
        $this->createOptionForm = $schema;

        return $this;
    }

    /**
     * Create the option yourself: `fn (array $data, Schema $schema): mixed => $key`. Required
     * for selects without a relationship.
     */
    public function createOptionUsing(?Closure $callback): static
    {
        $this->createOptionUsing = $callback;

        return $this;
    }

    /**
     * Customise the create action: `fn (Action $action) => $action->modalWidth('lg')`.
     */
    public function createOptionAction(?Closure $callback): static
    {
        $this->modifyCreateOptionActionUsing = $callback;

        return $this;
    }

    public function createOptionModalHeading(string|Closure|null $heading): static
    {
        $this->createOptionModalHeading = $heading;

        return $this;
    }

    /**
     * @param  array<Component>|Closure|null  $schema
     */
    public function editOptionForm(array|Closure|null $schema): static
    {
        $this->editOptionForm = $schema;

        return $this;
    }

    /**
     * Update the selected option yourself: `fn (array $data, Model $record, Schema $schema)`.
     */
    public function updateOptionUsing(?Closure $callback): static
    {
        $this->updateOptionUsing = $callback;

        return $this;
    }

    public function editOptionAction(?Closure $callback): static
    {
        $this->modifyEditOptionActionUsing = $callback;

        return $this;
    }

    public function editOptionModalHeading(string|Closure|null $heading): static
    {
        $this->editOptionModalHeading = $heading;

        return $this;
    }

    public function getCreateOptionActionName(): string
    {
        return 'createOption';
    }

    public function getEditOptionActionName(): string
    {
        return 'editOption';
    }

    /**
     * @return array<Component>
     */
    public function getCreateOptionFormComponents(): array
    {
        return $this->evaluate($this->createOptionForm) ?? [];
    }

    /**
     * @return array<Component>
     */
    public function getEditOptionFormComponents(): array
    {
        return $this->evaluate($this->editOptionForm) ?? [];
    }

    public function hasCreateOptionForm(): bool
    {
        return $this->createOptionForm !== null && ($this->hasRelationship() || $this->createOptionUsing !== null);
    }

    public function hasEditOptionForm(): bool
    {
        return $this->editOptionForm !== null && ($this->hasRelationship() || $this->updateOptionUsing !== null);
    }

    /**
     * The create and edit option buttons follow the field's own suffix actions.
     *
     * @return array<Action>
     */
    public function getSuffixActions(): array
    {
        $actions = parent::getSuffixActions();

        if (($create = $this->getCreateOptionAction()) !== null) {
            $actions[] = $this->prepareFieldAction($create);
        }

        if (($edit = $this->getEditOptionAction()) !== null) {
            $actions[] = $this->prepareFieldAction($edit);
        }

        return $actions;
    }

    public function getCreateOptionAction(): ?Action
    {
        if (! $this->hasCreateOptionForm()) {
            return null;
        }

        $label = Labels::forSentence($this->getLabel() ?? '');

        $action = Action::make($this->getCreateOptionActionName())
            ->label(__('alfakit::actions.create.modal.submit'))
            ->hiddenLabel()
            ->tooltip(__('alfakit::actions.create.modal.submit'))
            ->icon('plus')
            ->iconButton()
            ->model(fn (): ?string => $this->getOptionModelClass())
            ->modalHeading(fn (): string => $this->evaluate($this->createOptionModalHeading) ?? __('alfakit::actions.create.modal.heading', ['label' => $label]))
            ->modalSubmitActionLabel(__('alfakit::actions.create.modal.submit'))
            ->schema(fn (): array => $this->getCreateOptionFormComponents())
            ->visible(fn (): bool => $this->canChangeOptions())
            // Creating a related record needs the related model's `create` ability.
            ->authorize(fn (): bool => $this->createOptionUsing !== null || Authorization::allows('create', $this->getOptionModelClass()))
            ->action(function (array $data, ?Schema $schema): void {
                $key = $this->createOption($data, $schema);

                if ($key !== null) {
                    $this->selectCreatedOption($key);
                }
            });

        if ($this->modifyCreateOptionActionUsing !== null) {
            $action = $this->evaluate($this->modifyCreateOptionActionUsing, ['action' => $action], [Action::class => $action]) ?? $action;
        }

        return $action;
    }

    public function getEditOptionAction(): ?Action
    {
        if (! $this->hasEditOptionForm()) {
            return null;
        }

        $label = Labels::forSentence($this->getLabel() ?? '');

        $action = Action::make($this->getEditOptionActionName())
            ->label(__('alfakit::actions.edit.label'))
            ->hiddenLabel()
            ->tooltip(__('alfakit::actions.edit.label'))
            ->icon('pencil')
            ->iconButton()
            ->record(fn (): ?Model => $this->getSelectedOptionRecord())
            ->modalHeading(fn (): string => $this->evaluate($this->editOptionModalHeading) ?? __('alfakit::actions.edit.modal.heading', ['label' => $label]))
            ->modalSubmitActionLabel(__('alfakit::actions.edit.modal.submit'))
            ->schema(fn (): array => $this->getEditOptionFormComponents())
            ->visible(fn (): bool => $this->canChangeOptions() && ! $this->isMultiple() && $this->getSelectedOptionRecord() !== null)
            ->authorize(fn (): bool => $this->updateOptionUsing !== null || Authorization::allows('update', $this->getSelectedOptionRecord()))
            ->action(function (array $data, ?Schema $schema): void {
                $record = $this->getSelectedOptionRecord();

                // The option may have left the relationship query since the modal opened.
                abort_if($record === null, 404);

                $this->updateOption($record, $data, $schema);
            });

        if ($this->modifyEditOptionActionUsing !== null) {
            $action = $this->evaluate($this->modifyEditOptionActionUsing, ['action' => $action], [Action::class => $action]) ?? $action;
        }

        return $action;
    }

    protected function canChangeOptions(): bool
    {
        return ! $this->isDisabled() && ! $this->isReadOnly();
    }

    /**
     * @return class-string<Model>|null
     */
    protected function getOptionModelClass(): ?string
    {
        $relationship = $this->hasRelationship() ? $this->getRelationship() : null;

        return $relationship instanceof Relation ? $relationship->getRelated()::class : null;
    }

    /**
     * The related record the (single) select currently points at, looked up through the
     * same query that lists the options.
     */
    public function getSelectedOptionRecord(): ?Model
    {
        $state = $this->getState();

        if ($this->isMultiple() || ! is_scalar($state) || $state === '' || ! $this->hasRelationship()) {
            return null;
        }

        $query = $this->getRelationshipQuery();

        if ($query === null) {
            return null;
        }

        return $query->where($query->qualifyColumn($this->getRelationshipKeyName()), $state)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createOption(array $data, ?Schema $schema): mixed
    {
        if ($this->createOptionUsing !== null) {
            return $this->evaluate($this->createOptionUsing, ['data' => $data, 'schema' => $schema, 'form' => $schema], $schema !== null ? [Schema::class => $schema] : []);
        }

        $relationship = $this->getRelationship();

        if ($relationship === null) {
            return null;
        }

        $record = $relationship->getRelated()->newInstance();
        $record->fill($data);
        $record = TenantScope::associate($record);

        $schema?->record($record)->saveRelationships();

        return $record->getAttribute($this->getRelationshipKeyName());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function updateOption(Model $record, array $data, ?Schema $schema): void
    {
        if ($this->updateOptionUsing !== null) {
            $this->evaluate($this->updateOptionUsing, ['data' => $data, 'record' => $record, 'schema' => $schema, 'form' => $schema], [Model::class => $record, $record::class => $record]);

            return;
        }

        $record->fill($data)->save();
        $schema?->record($record)->saveRelationships();
    }

    protected function selectCreatedOption(mixed $key): void
    {
        $key = is_scalar($key) ? (string) $key : $key;

        if (! $this->isMultiple()) {
            $this->state($key);

            return;
        }

        $state = array_map(static fn (mixed $value): mixed => is_scalar($value) ? (string) $value : $value, Arr::wrap($this->getState() ?? []));

        if (! in_array($key, $state, true)) {
            $state[] = $key;
        }

        $this->state(array_values($state));
    }
}
