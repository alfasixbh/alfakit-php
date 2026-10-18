<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedSchema;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateRecord extends Page
{
    protected static bool $canCreateAnother = true;

    protected ?Model $createdRecord = null;

    /** The record just created, for `afterCreate()` hooks (`$this->record`), as on edit pages. */
    protected ?Model $record = null;

    public static function canAccess(): bool
    {
        return static::getResource()::canCreate();
    }

    public function getTitle(): string
    {
        return static::$title ?? __('alfakit::panel.resources.create.title', ['label' => static::getResource()::getModelLabel()]);
    }

    public function getBreadcrumb(): string
    {
        return static::$breadcrumb ?? __('alfakit::panel.resources.create.breadcrumb');
    }

    public function form(Schema $schema): Schema
    {
        return static::getResource()::form($schema);
    }

    protected function getFormOperation(): string
    {
        return 'create';
    }

    protected function getFormActions(): array
    {
        return array_values(array_filter([
            $this->getCreateFormAction(),
            static::canCreateAnother() ? $this->getCreateAnotherFormAction() : null,
            $this->getCancelFormAction(),
        ]));
    }

    protected function getCreateFormAction(): Action
    {
        return Action::make('create')
            ->label(__('alfakit::forms.actions.create'))
            ->color(Color::Primary)
            ->button()
            ->keyBindings(['mod+s'])
            ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
            ->modifyFormUsing(fn (Schema $schema): Schema => $schema->operation('create'))
            ->beforeFormValidated(fn () => $this->callHook('beforeValidate'))
            ->afterFormValidated(fn () => $this->callHook('afterValidate'))
            ->action(fn (array $data) => $this->create($data));
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return Action::make('createAnother')
            ->label(__('alfakit::forms.actions.create_another'))
            ->color(Color::Gray)
            ->outlined()
            ->button()
            ->keyBindings(['mod+shift+s'])
            ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
            ->modifyFormUsing(fn (Schema $schema): Schema => $schema->operation('create'))
            ->beforeFormValidated(fn () => $this->callHook('beforeValidate'))
            ->afterFormValidated(fn () => $this->callHook('afterValidate'))
            ->action(fn (array $data) => $this->create($data, another: true));
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label(__('alfakit::forms.actions.cancel'))
            ->color(Color::Gray)
            ->ghost()
            ->button()
            ->url(static::getResource()::getUrl());
    }

    public static function canCreateAnother(): bool
    {
        return static::$canCreateAnother;
    }

    /**
     * Runs after validation: `beforeCreate()`, `mutateFormDataBeforeCreate()`, the record
     * creation, `afterCreate()` (hooks of traits too: `afterCreateHasAuditLog()`). Call
     * `$this->halt()` from a hook to stop without creating the record.
     *
     * @param  array<string, mixed>  $data  Validated form data
     */
    public function create(array $data, bool $another = false): mixed
    {
        $this->callHook('beforeCreate', [$data]);

        $data = $this->mutateFormDataBeforeCreate($data);

        // Relationship fields are saved from the form of the action that ran: "create & create
        // another" fills its own form, not the one of the "create" action.
        $action = $this->getActions()[$another ? 'createAnother' : 'create'] ?? $this->getActions()['create'];

        $this->createdRecord = DB::transaction(function () use ($data, $action): Model {
            $record = $this->handleRecordCreation($data);

            $action->getForm()?->record($record)->saveRelationships();

            return $record;
        });

        $this->record = $this->createdRecord;

        $this->callHook('afterCreate');

        $this->getCreatedNotification()?->send();

        if ($another) {
            $this->preserveDataForNextForm($action->getForm()?->getRawState() ?? $data);

            return redirect()->to(static::getResource()::getUrl('create'));
        }

        return redirect()->to($this->getRedirectUrl());
    }

    /**
     * The values "Create & create another" carries over to the empty form, e.g.
     * `Arr::only($data, ['is_published', 'author_id'])`. Nothing by default.
     *
     * @param  array<string, mixed>  $data  The form's state as submitted
     * @return array<string, mixed>
     */
    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function preserveDataForNextForm(array $state): void
    {
        unset($state[Schema::READ_ONLY_STATE_KEY]);

        $preserved = $this->preserveFormDataWhenCreatingAnother($state);

        if ($preserved !== [] && app()->bound('session')) {
            session()->flash(self::preservedDataSessionKey(), $preserved);
        }
    }

    protected static function preservedDataSessionKey(): string
    {
        return 'alfakit.create_another.'.static::class;
    }

    /**
     * The defaults, plus the values `preserveFormDataWhenCreatingAnother()` kept from the
     * record created just before ("Create & create another").
     */
    protected function fillFormState(Schema $form): void
    {
        parent::fillFormState($form);

        if (! $this->shouldCallFillHooks || ! app()->bound('session')) {
            return;
        }

        $preserved = session()->get(self::preservedDataSessionKey());

        if (is_array($preserved) && $preserved !== []) {
            $form->rawState([...$form->getRawState(), ...$preserved]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $model = static::getResource()::getModel();
        $record = new $model;
        $record->fill($data);

        // `scopeToUser()`: the new record belongs to the signed-in user.
        static::getResource()::associateRecordWithUser($record);

        // Nested resources create the record under the parent record of the page.
        $attachToParent = static::getResource()::associateRecordWithParent($record);

        $tenant = $this->getPanelTenant();

        if ($tenant !== null && static::getResource()::isScopedToTenant()) {
            $record = $this->associateRecordWithTenant($record, $tenant);
        } else {
            $record->save();
        }

        if ($attachToParent) {
            static::getResource()::attachRecordToParent($record);
        }

        return $record;
    }

    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        // `$tenantRelationshipName` on the resource: create through the tenant's relationship.
        $tenantRelationship = static::getResource()::getTenantRelationship($tenant);

        if ($tenantRelationship instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough) {
            $record->save();

            return $record;
        }

        if ($tenantRelationship instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrMany || $tenantRelationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            $tenantRelationship->save($record);

            return $record;
        }

        $relationship = $record->{static::getResource()::getTenantOwnershipRelationshipName()}();

        if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            $relationship->associate($tenant);
            $record->save();

            return $record;
        }

        $record->save();

        if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            $relationship->attach($tenant);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function beforeCreate(array $data): void {}

    protected function afterCreate(): void {}

    /** Runs when the page loads, before the form is filled with its defaults. */
    protected function beforeFill(): void {}

    /** Runs when the page loads, after the form was filled. */
    protected function afterFill(): void {}

    /** Runs on submit, before the form is validated. */
    protected function beforeValidate(): void {}

    /** Runs on submit, after the form was validated, before `mutateFormDataBeforeCreate()`. */
    protected function afterValidate(): void {}

    protected function getCreatedNotification(): ?Notification
    {
        $title = $this->getCreatedNotificationTitle();

        return $title === null ? null : Notification::make()->success()->title($title);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('alfakit::forms.actions.created');
    }

    public function getCreatedRecord(): ?Model
    {
        return $this->createdRecord;
    }

    /**
     * After creating: the panel's `resourceCreatePageRedirect()` when set, else the View
     * page, the Edit page or the list, whichever the user may open first.
     */
    protected function getRedirectUrl(): string
    {
        $resource = static::getResource();
        $record = $this->createdRecord;

        $target = $this->getPanelResourcePageRedirect('create');

        if ($target !== null && ($url = $this->getResourcePageRedirectUrl($target, $record)) !== null) {
            return $url;
        }

        if ($record !== null && $resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }

        if ($record !== null && $resource::hasPage('edit') && $resource::canEdit($record)) {
            return $resource::getUrl('edit', ['record' => $record]);
        }

        return $resource::getUrl();
    }

    /**
     * The form, with any `content()` components around it (`getFormContentComponent()`).
     */
    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return $this->composeContentBlocks(['form' => $form === null ? null : $this->serializeForm($form)]);
    }

    /**
     * Places the page form inside `content()`.
     */
    public function getFormContentComponent(): EmbeddedSchema
    {
        return EmbeddedSchema::make('form');
    }
}
