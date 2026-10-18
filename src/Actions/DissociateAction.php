<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RuntimeException;

class DissociateAction extends Action
{
    public static function make(string $name = 'dissociate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.dissociate.label'));
        $this->icon('alfakit::actions.dissociate');
        $this->color(Color::Danger);
        $this->requiresConfirmation();
        $this->modalHeading(fn (): string => __('alfakit::actions.dissociate.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.dissociate.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.dissociate.notification'));

        $this->action(function (Model $record): void {
            $context = $this->getContext();
            $relationship = $context instanceof RelationManager ? $context->getRelationship() : null;

            if (! $relationship instanceof HasMany && ! $relationship instanceof MorphMany) {
                throw new RuntimeException('DissociateAction can only be used with HasMany or MorphMany relationships inside a relation manager.');
            }

            $record->setAttribute($relationship->getForeignKeyName(), null);

            if ($relationship instanceof MorphMany) {
                $record->setAttribute($relationship->getMorphType(), null);
            }

            $record->save();
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
