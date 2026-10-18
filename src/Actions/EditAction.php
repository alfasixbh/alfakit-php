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

        $this->authorize(fn (?Model $record): bool => $this->resourceAuthorizes('update', $record));

        $this->action(function (array $data, Model $record, EditAction $action): Model {
            $data = $action->mutateDataUsing !== null ? $action->evaluate($action->mutateDataUsing, ['data' => $data]) : $data;

            return DB::transaction(function () use ($record, $data, $action): Model {
                if ($action->saveUsing !== null) {
                    $action->evaluate($action->saveUsing, ['data' => $data]);
                } else {
                    // In a BelongsToMany relation manager, `withPivot()` fields go to the pivot row.
                    [$attributes, $pivot] = $action->splitPivotData($data);

                    $record->fill($attributes)->save();

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
