<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Enumerable;
use RuntimeException;

/**
 * Detaches the selected records of a BelongsToMany relation manager. The records come
 * from the relation manager's own query, so only records attached to the owner record
 * can be detached.
 */
class DetachBulkAction extends BulkAction
{
    public static function make(string $name = 'detach'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.detach.label'));
        $this->icon('unlink');
        $this->color(Color::Danger);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit::actions.detach_bulk.heading'));
        $this->modalSubmitActionLabel(__('alfakit::actions.detach.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.detach_bulk.notification'));

        $this->action(function (Enumerable $records): void {
            $context = $this->getContext();
            $relationship = $context instanceof RelationManager ? $context->getRelationship() : null;

            if (! $relationship instanceof BelongsToMany) {
                throw new RuntimeException('DetachBulkAction can only be used with BelongsToMany relationships inside a relation manager.');
            }

            $records->chunk(500)->each(static function (Enumerable $chunk) use ($relationship): void {
                // Models, or plain keys with `fetchSelectedRecords(false)`.
                $relationship->detach($chunk->map(static fn (mixed $record): mixed => $record instanceof Model ? $record->getKey() : $record)->values()->all());
            });
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
