<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Actions\Undo\UndoStrategy;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeleteAction extends Action
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
        $this->modalHeading(fn (): string => __('alfakit::actions.delete.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.delete.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.delete.notification'));

        $this->authorize(fn (?Model $record): bool => $this->resourceAuthorizes('delete', $record));

        $this->hidden(fn (?Model $record): bool => $record !== null && in_array(SoftDeletes::class, class_uses_recursive($record), true) && $record->trashed());

        $this->action(fn (Model $record): ?bool => $record->delete());

        $this->successRedirectUrl(function (?Model $record): ?string {
            $context = $this->getContext();

            // Deleting from an edit/view page returns to the index.
            if ($record !== null && is_object($context) && method_exists($context, 'getRecord') && $context->getRecord()?->is($record)) {
                return $this->getResourceUrl('index');
            }

            return null;
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
