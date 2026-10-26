<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Actions\Undo\UndoStrategy;
use AlfaSix\Alfakit\Support\Enums\Color;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditAction extends Action
{
    use InteractsWithResource;

    protected ?Closure $mutateDataUsing = null;

    protected ?Closure $saveUsing = null;

    /** The built-in save; replaced by a custom `action()`. */
    protected ?Closure $saveAction = null;

    public static function make(string $name = 'edit'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.edit.label'));
        $this->icon('alfakit::actions.edit');
        $this->color(Color::Primary);
        $this->modalHeading(fn (): string => __('alfakit::actions.edit.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.edit.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.edit.notification'));

        $this->url(fn (?Model $record): ?string => $this->hasForm() || $record === null ? null : $this->getResourceUrl('edit', $record));

        // `offeredBeforeRecord()` (a calendar event's click action): before its record is
        // known it is offered to users who may view the records, and `update` is checked when
        // the record is resolved (the modal opens or the action runs).
        $this->authorize(fn (?Model $record): bool => $record === null && $this->isOfferedBeforeRecord
            ? $this->resourceAuthorizes('viewAny')
            : $this->resourceAuthorizes('update', $record));

        $this->action($this->saveAction = function (array $data, ?Model $record, EditAction $action): Model {
            // Offered without a record (see above), it only ever runs on one.
            abort_if($record === null, 404);

            $data = $action->mutateDataUsing !== null ? $action->evaluate($action->mutateDataUsing, ['data' => $data]) : $data;

            return DB::transaction(function () use ($record, $data, $action): Model {
                if ($action->saveUsing !== null) {
                    $action->evaluate($action->saveUsing, ['data' => $data]);
                } else {
                    // In a BelongsToMany relation manager, `withPivot()` fields go to the pivot row.
                    [$attributes, $pivot] = $action->splitPivotData($data);

                    $record->fill($attributes);

                    // `scopeToUser()` resources: the record stays the signed-in user's.
                    $resource = $action->getResource();

                    if ($resource !== null && is_a($record, $resource::getModel())) {
                        $resource::associateRecordWithUser($record);
                    }

                    $record->save();

                    if ($pivot !== []) {
                        $action->getPivotRelationship()?->updateExistingPivot($record->getKey(), $pivot);
                    }
                }

                $action->getForm()?->saveRelationships();

                return $record;
            });
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }

    /**
     * Without a form (or other modal content), a page to link to (a resource's edit page),
     * `using()` or its own `action()`, there is nothing to edit: the action is hidden instead of "saving"
     * nothing (e.g. `EditAction::make()` in a table outside a resource).
     */
    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        return ! $this->isOfferedBeforeRecord
            && $this->action === $this->saveAction
            && $this->saveUsing === null
            && ! $this->hasModal()
            && $this->getExtraModalFooterActions() === []
            && $this->getUrl() === null;
    }

    public function mutateDataUsing(?Closure $callback): static
    {
        $this->mutateDataUsing = $callback;

        return $this;
    }

    public function using(?Closure $callback): static
    {
        $this->saveUsing = $callback;

        return $this;
    }

    /**
     * Undoable: the attributes the save changed are put back (refused when the record
     * changed again since).
     */
    protected function getDefaultUndoStrategy(): ?UndoStrategy
    {
        return UndoStrategy::Snapshot;
    }

    protected function getUndoAbility(): ?string
    {
        return 'update';
    }
}
