<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Actions\Undo\UndoStrategy;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Enumerable;

class DeleteBulkAction extends BulkAction
{
    use InteractsWithResource;

    public static function make(string $name = 'delete'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.delete.label'));
        $this->icon('alfakit::actions.delete');
        $this->color(Color::Danger);
        // `undoable()` on a soft-deleting model: no confirmation by default, the toast offers
        // "Undo" instead (`skipConfirmationWhenUndoable(false)` keeps it).
        $this->requiresConfirmation(fn (): bool => ! ($this->isUndoable() && $this->skipsConfirmationWhenUndoable()));
        $this->modalHeading(__('alfakit::actions.delete.bulk.heading'));
        $this->modalSubmitActionLabel(__('alfakit::actions.delete.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.delete.bulk.notification'));

        $this->authorize(fn (): bool => $this->resourceAuthorizes('deleteAny'));
        // Each selected record is checked against the policy too; refused ones are skipped.
        $this->authorizeIndividualRecords('delete');

        // With `fetchSelectedRecords(false)` the keys are deleted by query, 500 at a time
        // (soft-deleting models are still soft-deleted; model events don't fire).
        $this->action(function (Enumerable $records): void {
            $this->processSelectedRecords(
                $records,
                static fn (Model $record) => $record->delete(),
                static fn (Builder $query) => $query->delete(),
            );
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }

    /**
     * Undoable on soft-deleting models: the records are restored.
     */
    protected function getDefaultUndoStrategy(): ?UndoStrategy
    {
        $model = $this->getUndoModelClass();

        return $model !== null && in_array(SoftDeletes::class, class_uses_recursive($model), true) ? UndoStrategy::Restore : null;
    }

    protected function getUndoAbility(): ?string
    {
        return 'delete';
    }
}
