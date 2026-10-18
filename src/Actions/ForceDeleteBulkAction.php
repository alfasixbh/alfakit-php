<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Enumerable;

class ForceDeleteBulkAction extends BulkAction
{
    use InteractsWithResource;

    public static function make(string $name = 'forceDelete'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.force_delete.label'));
        $this->icon('alfakit::actions.force-delete');
        $this->color(Color::Danger);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit::actions.force_delete.bulk.heading'));
        $this->modalDescription(__('alfakit::actions.force_delete.modal.description'));
        $this->modalSubmitActionLabel(__('alfakit::actions.force_delete.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.force_delete.bulk.notification'));

        $this->authorize(fn (): bool => $this->resourceAuthorizes('forceDeleteAny'));
        // Each selected record is checked against the policy too; refused ones are skipped.
        $this->authorizeIndividualRecords('forceDelete');

        // With `fetchSelectedRecords(false)` the keys are force-deleted by query, 500 at a time.
        $this->action(function (Enumerable $records): void {
            $this->processSelectedRecords(
                $records,
                static fn (Model $record) => method_exists($record, 'forceDelete') ? $record->forceDelete() : $record->delete(),
                static fn (Builder $query) => $query->withoutGlobalScope(SoftDeletingScope::class)->forceDelete(),
            );
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
