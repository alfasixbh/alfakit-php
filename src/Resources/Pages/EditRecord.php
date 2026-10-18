<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Realtime\RecordVersion;
use AlfaSix\Alfakit\Resources\Pages\Concerns\CanRefreshFormData;
use AlfaSix\Alfakit\Resources\Pages\Concerns\HasRelationManagers;
use AlfaSix\Alfakit\Resources\Pages\Concerns\InteractsWithRecord;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedRelationManagers;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedSchema;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\Serializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditRecord extends Page
{
    use CanRefreshFormData;
    use HasRelationManagers;
    use InteractsWithRecord;

    /**
     * Optimistic concurrency (refuse saving over somebody else's changes): null follows
     * the panel's `detectConflicts()` (on by default).
     */
    protected static ?bool $detectsConflicts = null;

    /**
     * `Action::undoable()` for the save button: the "Saved" toast offers "Undo" for
     * `$saveUndoSeconds`, which puts the changed attributes back (relationships are not
     * tracked) unless the record changed again since.
     */
    protected static bool $isSaveUndoable = false;

    protected static int $saveUndoSeconds = 8;

    public function mount(mixed ...$parameters): void
    {
        $this->record = $this->resolveRecord($parameters[0] ?? null);

        abort_unless(static::getResource()::canEdit($this->record), 403);
    }

    public function getTitle(): string
    {
        return static::$title ?? __('alfakit::panel.resources.edit.title', ['label' => $this->getRecordTitle() ?? static::getResource()::getModelLabel()]);
    }

    public function getBreadcrumb(): string
    {
        return static::$breadcrumb ?? __('alfakit::panel.resources.edit.breadcrumb');
    }

    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $breadcrumbs = [...$this->getParentBreadcrumbs(), $resource::getUrl() => $resource::getTitleCasePluralModelLabel()];

        if ($resource::hasPage('view') && $this->record !== null && $resource::canView($this->record)) {
            $breadcrumbs[$resource::getUrl('view', ['record' => $this->record])] = (string) $this->getRecordTitle();
        } elseif ($this->record !== null) {
            $breadcrumbs[] = (string) $this->getRecordTitle();
        }

        $breadcrumbs[] = $this->getBreadcrumb();

        return $breadcrumbs;
    }

    public function form(Schema $schema): Schema
    {
        return static::getResource()::form($schema);
    }

    protected function getFormActions(): array
    {
        return [$this->getSaveFormAction(), $this->getCancelFormAction()];
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('save')
            ->label(__('alfakit::forms.actions.save'))
            ->color(Color::Primary)
            ->button()
            ->keyBindings(['mod+s'])
            ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
            ->fillForm(fn (): array => $this->getForm()?->getRawState() ?? [])
            ->beforeFormValidated(fn () => $this->callHook('beforeValidate'))
            ->afterFormValidated(fn () => $this->callHook('afterValidate'))
            ->action(fn (array $data) => $this->save($data))
            // `$isSaveUndoable`: the "Saved" toast offers "Undo" (changed attributes put back).
            ->undoStrategy(\AlfaSix\Alfakit\Actions\Undo\UndoStrategy::Snapshot)
            ->undoable(seconds: static::$saveUndoSeconds, condition: fn (): bool => static::$isSaveUndoable);
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

    /**
     * Runs after validation: `beforeSave()`, `mutateFormDataBeforeSave()`, the update,
     * `afterSave()` (hooks of traits too: `afterSaveHasAuditLog()`). Call `$this->halt()`
     * from a hook to stop without saving.
     *
     * @param  array<string, mixed>  $data  Validated form data
     */
    public function save(array $data, bool $shouldRedirect = true): mixed
    {
        $this->ensureRecordIsUnchanged();

        $this->callHook('beforeSave', [$data]);

        $data = $this->mutateFormDataBeforeSave($data);

        DB::transaction(function () use ($data): void {
            $this->record = $this->handleRecordUpdate($this->record, $data);

            $this->getActions()['save']->getForm()?->record($this->record)->saveRelationships();
        });

        $this->callHook('afterSave');

        $this->getSavedNotification()?->send();

        if (! $shouldRedirect) {
            return null;
        }

        return redirect()->to($this->getRedirectUrl());
    }

    // ---------------------------------------------------------------- Edit conflicts

    /**
     * Whether saving checks that nobody else saved the record since the form was loaded:
     * `$detectsConflicts` on the page, else the panel's `detectConflicts()`.
     */
    public function detectsConflicts(): bool
    {
        return static::$detectsConflicts ?? $this->getPanel()->isDetectingConflicts();
    }

    /**
     * Refuse the save when the form was loaded from an older version of the record (the
     * renderer sends it as `recordVersion`). Saves without a version (code calling `save()`)
     * are not checked.
     *
     * @throws ValidationException
     */
    protected function ensureRecordIsUnchanged(): void
    {
        $submitted = request()->input(RecordVersion::INPUT);

        if ($this->record === null || ! is_string($submitted) || $submitted === '' || ! $this->detectsConflicts()) {
            return;
        }

        if (RecordVersion::matches($this->record, $submitted)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('alfakit::panel.realtime.conflict.refused_title'))
            ->body(__('alfakit::panel.realtime.conflict.refused_body'))
            ->send();

        throw ValidationException::withMessages([
            RecordVersion::CONFLICT_ERROR => __('alfakit::panel.realtime.conflict.refused_body'),
        ]);
    }

    /**
     * What changed on the record since the form was loaded: the record's current version
     * and, per field whose saved value differs from `$original`, its label, the saved value
     * (`theirs`, plus the raw `value` to adopt it) and the user's unsaved one (`mine`).
     * Hidden fields are left out, like in the form's own state.
     *
     * @param  array<string, mixed>  $original  The form state the page was loaded with
     * @param  array<string, mixed>  $mine  The form's current (unsaved) state
     * @return array{version: string|null, fields: list<array{path: string, label: string, theirs: string|null, mine: string|null, value: mixed, conflicting: bool}>}
     */
    public function getEditConflict(array $original, array $mine): array
    {
        $form = $this->getForm();

        if ($form === null || $this->record === null) {
            return ['version' => null, 'fields' => []];
        }

        $saved = $form->toPayload()['state'] ?? [];
        $saved = is_array($saved) ? $saved : [];
        $fields = [];

        foreach ($form->getFlatFields() as $path => $field) {
            $path = (string) $path;

            if (! Arr::has($saved, $path)) {
                continue;
            }

            $theirs = Arr::get($saved, $path);
            $before = Arr::get($original, $path);

            if (self::sameValue($theirs, $before)) {
                continue;
            }

            $current = Arr::has($mine, $path) ? Arr::get($mine, $path) : $before;

            $fields[] = [
                'path' => $path,
                'label' => Html::toText($field->getLabel()),
                'theirs' => self::displayValue($theirs),
                'mine' => self::displayValue($current),
                'value' => $theirs,
                // Both sides changed the field, differently.
                'conflicting' => ! self::sameValue($current, $before) && ! self::sameValue($current, $theirs),
            ];
        }

        return ['version' => RecordVersion::of($this->record), 'fields' => $fields];
    }

    protected static function sameValue(mixed $a, mixed $b): bool
    {
        return self::normalizeValue($a) === self::normalizeValue($b);
    }

    protected static function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, $value === '', $value === [] => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            is_array($value) => array_map(self::normalizeValue(...), $value),
            default => json_encode($value),
        };
    }

    protected static function displayValue(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '', $value === [] => null,
            is_bool($value) => $value ? __('alfakit::panel.realtime.conflict.yes') : __('alfakit::panel.realtime.conflict.no'),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }

    /**
     * Adds the record's version (`detectConflicts()`) and its presence channel
     * (`realtime()`) to the page.
     *
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        $panel = $this->getPanel();

        return Serializable::compact([
            ...parent::toProps(),
            'conflicts' => $this->record !== null && $this->detectsConflicts() ? ['version' => RecordVersion::of($this->record)] : null,
            'presence' => $panel->getRecordPresenceSchema($this->record, 'editing', $panel->auth()->user()),
        ]);
    }

    /**
     * Save only the fields of one form component (a section's own "Save" button), given
     * as the component or its key:
     *
     * ```php
     * Section::make('Shipping')->key('shipping')->schema([...])->footerActions([
     *     Action::make('saveShipping')->formId('form')
     *         ->action(fn (EditRecord $page) => $page->saveFormComponentOnly('shipping')),
     * ])
     * ```
     *
     * The form state comes from the action's request (`formId('form')` sends it); only the
     * component's fields are validated and written, other unsaved edits stay in the form.
     */
    public function saveFormComponentOnly(Component|string $component): void
    {
        $form = $this->getForm();

        if ($form === null || $this->record === null) {
            return;
        }

        if (is_string($component)) {
            $key = $component;
            $component = collect($form->getFlatComponents())->first(static fn (Component $candidate): bool => $candidate->getKey() === $key)
                ?? throw new InvalidArgumentException('The form of ['.static::class."] has no visible component with the key [{$key}].");
        }

        $submitted = request()->input('data');

        if (is_array($submitted)) {
            $form->applyClientState($submitted);
        }

        $paths = array_map(strval(...), array_keys($component->getChildSchema()->getFlatFields()));

        if ($paths === []) {
            return;
        }

        $form->validateOnly($paths);

        $state = $form->getState();
        $data = [];

        foreach ($paths as $path) {
            if (Arr::has($state, $path)) {
                Arr::set($data, $path, Arr::get($state, $path));
            }
        }

        DB::transaction(function () use ($data, $component): void {
            $this->record = $this->handleRecordUpdate($this->record, $data);

            $component->getChildSchema()->record($this->record)->saveRelationships();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data)->save();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function beforeSave(array $data): void {}

    protected function afterSave(): void {}

    /** Runs when the page loads, before the form is filled from the record. */
    protected function beforeFill(): void {}

    /** Runs when the page loads, after the form was filled from the record. */
    protected function afterFill(): void {}

    /** Runs on submit, before the form is validated. */
    protected function beforeValidate(): void {}

    /** Runs on submit, after the form was validated, before `beforeSave()`. */
    protected function afterValidate(): void {}

    protected function getSavedNotification(): ?Notification
    {
        $title = $this->getSavedNotificationTitle();

        return $title === null ? null : Notification::make()->success()->title($title);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('alfakit::forms.actions.saved');
    }

    /**
     * After saving: the panel's `resourceEditPageRedirect()` when set (and allowed), else
     * this Edit page again.
     */
    protected function getRedirectUrl(): string
    {
        $target = $this->getPanelResourcePageRedirect('edit');

        if ($target !== null && ($url = $this->getResourcePageRedirectUrl($target, $this->record)) !== null) {
            return $url;
        }

        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    /**
     * The form and the relation managers, with any `content()` components around them.
     */
    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return $this->composeContentBlocks($this->combineRelationManagerTabsWithContent([
            'form' => $form === null ? null : $this->serializeForm($form),
            'relationManagers' => $this->serializeRelationManagers(),
        ], 'form'));
    }

    /**
     * Places the page form inside `content()`.
     */
    public function getFormContentComponent(): EmbeddedSchema
    {
        return EmbeddedSchema::make('form');
    }

    /**
     * Places the relation managers inside `content()`.
     */
    public function getRelationManagersContentComponent(): EmbeddedRelationManagers
    {
        return EmbeddedRelationManagers::make();
    }
}
