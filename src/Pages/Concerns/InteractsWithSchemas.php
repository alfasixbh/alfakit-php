<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithSchemas
{
    protected ?Schema $cachedForm = null;

    protected ?Schema $cachedInfolist = null;

    /** @var array<string, mixed>|null */
    protected ?array $formData = null;

    /** True while the page renders for its own route (not for an internal request). */
    protected bool $shouldCallFillHooks = false;

    /**
     * Define the page form. Return the schema unchanged (no components) for no form.
     */
    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    /**
     * Define a read-only infolist for the page.
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema;
    }

    protected function getFormOperation(): string
    {
        return method_exists($this, 'getRecord') && $this->getRecord() !== null ? 'edit' : 'create';
    }

    protected function makeSchema(): Schema
    {
        $schema = Schema::make()->operation($this->getFormOperation())->owner($this);

        if (method_exists($this, 'getRecord') && ($record = $this->getRecord()) !== null) {
            $schema->record($record);
        } elseif (method_exists($this, 'getModel') && ($model = $this->getModel()) !== null) {
            $schema->model($model);
        }

        return $schema;
    }

    public function getForm(): ?Schema
    {
        if ($this->cachedForm !== null) {
            return $this->cachedForm;
        }

        $schema = $this->form($this->makeSchema());

        if ($schema->getComponents(withHidden: true) === []) {
            return null;
        }

        $this->cachedForm = $schema;
        $this->fillForm();

        return $schema;
    }

    public function getInfolist(): ?Schema
    {
        if ($this->cachedInfolist !== null) {
            return $this->cachedInfolist;
        }

        $schema = $this->infolist($this->makeSchema()->operation('view'));

        return $schema->getComponents(withHidden: true) === [] ? null : $this->cachedInfolist = $schema;
    }

    /**
     * Hydrate the form: from the record (mutated by `mutateFormDataBeforeFill`) or defaults.
     * When the page loads, `beforeFill()` and `afterFill()` (and their trait variants) run
     * around it; internal requests (actions, reactive refreshes) rebuild the form without them.
     */
    protected function fillForm(): void
    {
        $form = $this->cachedForm;

        if ($form === null) {
            return;
        }

        $callHooks = $this->shouldCallFillHooks && method_exists($this, 'callHook');

        if ($callHooks) {
            $this->callHook('beforeFill');
        }

        $this->fillFormState($form);

        if ($callHooks) {
            $this->callHook('afterFill');
        }
    }

    protected function fillFormState(Schema $form): void
    {
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;

        if ($record instanceof Model) {
            $form->fill();
            $form->loadStateFromRelationships();
            $data = $this->mutateFormDataBeforeFill($form->getRawState());
            $form->rawState($data);
        } else {
            $form->fill($this->formData);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    /**
     * Resolve a named schema for the internal endpoints (`form`, `infolist`, `action:{name}`).
     */
    public function getSchema(string $name): ?Schema
    {
        if ($name === 'form') {
            return $this->getForm();
        }

        if ($name === 'infolist') {
            return $this->getInfolist();
        }

        // Named pieces of custom pages: `form:customer`, `infolist:summary`.
        if ((str_starts_with($name, 'form:') || str_starts_with($name, 'infolist:')) && method_exists($this, 'getSchemaBlock')) {
            $block = $this->getSchemaBlock($name);

            return $block instanceof Schema ? $block : null;
        }

        if (str_starts_with($name, 'action:') && method_exists($this, 'resolveAction')) {
            [$component, $actionName] = $this->parseActionSchemaName(substr($name, strlen('action:')));

            return $this->resolveAction($component, $actionName)->getForm();
        }

        if (str_starts_with($name, 'relationManager:') && method_exists($this, 'getRelationManager')) {
            return null;
        }

        return null;
    }

    /**
     * `edit` → [page, edit]; `table.edit` → [table, edit]; `relationManager:X.edit` → [relationManager:X, edit]
     *
     * @return array{0: string, 1: string}
     */
    protected function parseActionSchemaName(string $name): array
    {
        if (! str_contains($name, '.')) {
            return ['page', $name];
        }

        $position = strrpos($name, '.');

        return [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeForm(Schema $form): array
    {
        return [
            'type' => 'Form',
            'key' => 'form',
            'component' => 'form',
            ...$form->toPayload(),
            'actions' => $this->serializeActions($this->getCachedFormActions()),
            'operation' => $form->getOperation(),
            // The form buttons show on the wizard's last step only (`HasWizard` pages).
            ...($this->hasWizardForm() ? ['wizard' => true] : []),
        ];
    }

    /**
     * Whether the page form is a wizard whose last step shows the form buttons.
     */
    protected function hasWizardForm(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeInfolist(Schema $infolist): array
    {
        return [
            'type' => 'Infolist',
            'key' => 'infolist',
            'components' => $infolist->toSchema(),
        ];
    }
}
