<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use Closure;
use Illuminate\Support\Arr;

trait HasForm
{
    /** @var array<\AlfaSix\Alfakit\Schemas\Components\Component>|Closure|null */
    protected array|Closure|null $formComponents = null;

    /** @var array<string, mixed>|Closure|null */
    protected array|Closure|null $formData = null;

    protected ?Closure $mutateFormDataUsing = null;

    protected ?Closure $mutateRecordDataUsing = null;

    /** Set while the modal is being opened, so the form-fill hooks run. */
    protected bool $isMountingForm = false;

    protected ?Closure $modifyFormUsing = null;

    protected ?Schema $form = null;

    protected bool|Closure $isFormDisabled = false;

    protected ?Closure $mountUsing = null;

    /**
     * @param  array<\AlfaSix\Alfakit\Schemas\Components\Component>|Closure|null  $components
     */
    public function schema(array|Closure|null $components): static
    {
        $this->formComponents = $components;
        $this->form = null;

        return $this;
    }

    /**
     * @param  array<\AlfaSix\Alfakit\Schemas\Components\Component>|Closure|null  $components
     */
    public function form(array|Closure|null $components): static
    {
        return $this->schema($components);
    }

    /**
     * Initial form data. Defaults to the record's attributes when a record is set.
     *
     * @param  array<string, mixed>|Closure|null  $data
     */
    public function fillForm(array|Closure|null $data): static
    {
        $this->formData = $data;
        $this->form = null;

        return $this;
    }

    public function mutateFormDataUsing(?Closure $callback): static
    {
        $this->mutateFormDataUsing = $callback;

        return $this;
    }

    /**
     * Change the record's attributes before they fill the modal form:
     * `fn (array $data, Model $record): array => [...$data, 'title' => strtoupper($data['title'])]`.
     * Ignored when `fillForm()` sets the data.
     */
    public function mutateRecordDataUsing(?Closure $callback): static
    {
        $this->mutateRecordDataUsing = $callback;
        $this->form = null;

        return $this;
    }

    /**
     * Customise the built Schema (e.g. `->columns(1)`) before it is used.
     */
    public function modifyFormUsing(?Closure $callback): static
    {
        $this->modifyFormUsing = $callback;

        return $this;
    }

    /**
     * Disable every field of the modal form (a read-only review before confirming).
     */
    public function disabledForm(bool|Closure $condition = true): static
    {
        $this->isFormDisabled = $condition;
        $this->form = null;

        return $this;
    }

    public function isFormDisabled(): bool
    {
        return (bool) $this->evaluate($this->isFormDisabled);
    }

    /**
     * Run code when the action is mounted (its modal opens, or it runs without one). With a
     * form, it replaces the default fill, so call `$form->fill()` yourself:
     * `fn (Schema $form) => $form->fill(['title' => 'Draft'])`. `$this->halt()` /
     * `$this->cancel()` stop the modal from opening.
     */
    public function mountUsing(?Closure $callback): static
    {
        $this->mountUsing = $callback;
        $this->form = null;

        return $this;
    }

    /**
     * Mount the action: build and fill its form (running `mountUsing()` and the form-fill
     * hooks) or, without a form, just run `mountUsing()`.
     */
    public function mount(): ?Schema
    {
        if (! $this->hasForm()) {
            if ($this->mountUsing !== null) {
                $this->evaluate($this->mountUsing, ['form' => null, 'schema' => null]);
            }

            return null;
        }

        return $this->mountForm();
    }

    public function hasForm(): bool
    {
        return $this->getFormComponents() !== [];
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public function getFormComponents(): array
    {
        // `->steps([...])` turns the modal form into a wizard.
        if (method_exists($this, 'hasWizard') && $this->hasWizard()) {
            return $this->getWizardFormComponents();
        }

        return $this->evaluate($this->formComponents) ?? [];
    }

    /**
     * The hydrated form schema for this action (built once per action instance).
     */
    public function getForm(): ?Schema
    {
        if (! $this->hasForm()) {
            return null;
        }

        if ($this->form !== null) {
            return $this->form;
        }

        // Modal forms bind to models; an array row (custom `records()`) is reached through
        // the action's `$record` instead.
        $record = $this->getRecordModel();

        $context = $this->getContext();

        $schema = Schema::make()
            ->components($this->getFormComponents())
            ->operation($record !== null ? 'edit' : 'create')
            ->owner(is_object($context) ? $context : null);

        if ($record !== null) {
            $schema->record($record);
        } elseif ($model = $this->getModel()) {
            $schema->model($model);
        }

        if ($this->modifyFormUsing !== null) {
            $schema = $this->evaluate($this->modifyFormUsing, ['schema' => $schema, 'form' => $schema], [Schema::class => $schema]) ?? $schema;
        }

        if ($this->isFormDisabled()) {
            foreach ($schema->getFlatComponents(withHidden: true) as $component) {
                $component->disabled();
            }
        }

        if ($this->isMountingForm) {
            $this->callFormHook('beforeFormFilled', ['schema' => $schema, 'form' => $schema], [Schema::class => $schema]);
        }

        $data = $this->evaluate($this->formData);

        if ($this->isMountingForm && $this->mountUsing !== null) {
            // `mountUsing()` replaces the default fill.
            $this->form = $schema;
            $this->evaluate($this->mountUsing, ['schema' => $schema, 'form' => $schema], [Schema::class => $schema]);
        } elseif ($data !== null) {
            $schema->fill($data);
        } elseif ($record !== null && ($this->mutateRecordDataUsing !== null || $this->getPivotColumns() !== [])) {
            $this->fillFromRecordData($schema, $record);
            $schema->loadStateFromRelationships();
        } elseif ($record !== null) {
            $schema->fill();
            $schema->loadStateFromRelationships();
        } else {
            $schema->fill($this->getPageFormFill($schema, $context));
        }

        $this->form = $schema;

        if ($this->isMountingForm) {
            $this->callFormHook('afterFormFilled', ['schema' => $schema, 'form' => $schema, 'data' => $schema->getRawState()], [Schema::class => $schema]);
        }

        return $schema;
    }

    /**
     * Build the form for the modal being opened: like `getForm()`, plus the
     * `beforeFormFilled()` / `afterFormFilled()` hooks.
     */
    public function mountForm(): ?Schema
    {
        if (! $this->hasForm()) {
            return null;
        }

        $this->form = null;
        $this->isMountingForm = true;

        try {
            return $this->getForm();
        } finally {
            $this->isMountingForm = false;
        }
    }

    /**
     * Fill from the record's attributes (plus its pivot attributes in a BelongsToMany
     * relation manager), passed through `mutateRecordDataUsing()`. Like `Schema::fill()`,
     * only the attributes the fields read reach the browser.
     */
    protected function fillFromRecordData(Schema $schema, \Illuminate\Database\Eloquent\Model $record): void
    {
        $data = [...$record->attributesToArray(), ...$this->getPivotAttributes($record)];

        if ($this->mutateRecordDataUsing !== null) {
            $data = $this->evaluate($this->mutateRecordDataUsing, ['data' => $data, 'record' => $record]) ?? $data;
        }

        $keys = array_values(array_unique(array_map(
            static fn (string $path): string => explode('.', $path)[0],
            array_map('strval', array_keys($schema->getFlatFields(withHidden: true))),
        )));

        $schema->rawState(Arr::only((array) $data, $keys));

        // Layout components bound to a relationship read their related record, as
        // `Schema::fill()` does when it fills from the record itself.
        foreach ($schema->getRelationshipComponents(withHidden: true) as $component) {
            $component->fillStateFromRelationship();
        }

        $schema->fill($schema->getRawState());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateFormData(array $data): array
    {
        if ($this->mutateFormDataUsing === null) {
            return $data;
        }

        return $this->evaluate($this->mutateFormDataUsing, ['data' => $data]) ?? $data;
    }

    /**
     * A page's button whose form is the page's own fields
     * (`->form(fn () => $this->getForm()->getComponents())`) starts from what the page
     * filled them with. The browser's values are then applied on top, as on the page's form,
     * so what compares the two (file paths a field may keep, the add / delete locks of a
     * Repeater or KeyValue) sees the page's rows and files instead of an empty form.
     *
     * @return array<string, mixed>|null
     */
    protected function getPageFormFill(Schema $schema, mixed $context): ?array
    {
        if (! $context instanceof Page) {
            return null;
        }

        $topLevel = static fn (Schema $form): array => array_values(array_unique(array_map(
            static fn (string $path): string => explode('.', $path)[0],
            array_keys($form->getFlatFields(withHidden: true)),
        )));

        $names = $topLevel($schema);
        $pageForm = $names === [] ? null : $context->getForm();

        // Only when every field of this form is one of the page's.
        if ($pageForm === null || $pageForm === $schema || array_diff($names, $topLevel($pageForm)) !== []) {
            return null;
        }

        $state = $pageForm->getServerStateAt('');

        return Arr::only(is_array($state) ? $state : $pageForm->getRawState(), $names);
    }
}
