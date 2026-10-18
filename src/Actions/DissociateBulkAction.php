<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Enumerable;
use RuntimeException;

/**
 * Dissociates the selected records of a HasMany / MorphMany relation manager (clears
 * their foreign key, and morph type). Only records of the owner record are affected.
 */
class DissociateBulkAction extends BulkAction
{
    public static function make(string $name = 'dissociate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.dissociate.label'));
        $this->icon('unlink');
        $this->color(Color::Danger);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit::actions.dissociate_bulk.heading'));
        $this->modalSubmitActionLabel(__('alfakit::actions.dissociate.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.dissociate_bulk.notification'));

        $this->action(function (Enumerable $records): void {
            $context = $this->getContext();
            $relationship = $context instanceof RelationManager ? $context->getRelationship() : null;

            if (! $relationship instanceof HasMany && ! $relationship instanceof MorphMany) {
                throw new RuntimeException('DissociateBulkAction can only be used with HasMany or MorphMany relationships inside a relation manager.');
            }

            $records->each(static function (Model $record) use ($relationship): void {
                $record->setAttribute($relationship->getForeignKeyName(), null);

                if ($relationship instanceof MorphMany) {
                    $record->setAttribute($relationship->getMorphType(), null);
                }

                $record->save();
            });
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
