<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Enumerable;

class RestoreBulkAction extends BulkAction
{
    use InteractsWithResource;

    public static function make(string $name = 'restore'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.restore.label'));
        $this->icon('alfakit::actions.restore');
        $this->color(Color::Gray);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit::actions.restore.bulk.heading'));
        $this->modalSubmitActionLabel(__('alfakit::actions.restore.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.restore.bulk.notification'));

        $this->authorize(fn (): bool => $this->resourceAuthorizes('restoreAny'));
        // Each selected record is checked against the policy too; refused ones are skipped.
        $this->authorizeIndividualRecords('restore');

        // With `fetchSelectedRecords(false)` the keys are restored by query, 500 at a time.
        $this->action(function (Enumerable $records): void {
            $this->processSelectedRecords(
                $records,
                static fn (Model $record) => method_exists($record, 'restore') ? $record->restore() : null,
                static function (Builder $query): void {
                    $model = $query->getModel();

                    if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getDeletedAtColumn')) {
                        $query->withoutGlobalScope(SoftDeletingScope::class)->update([$model->getDeletedAtColumn() => null]);
                    }
                },
            );
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
