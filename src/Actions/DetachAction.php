<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RuntimeException;

class DetachAction extends Action
{
    public static function make(string $name = 'detach'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.detach.label'));
        $this->icon('alfakit::actions.detach');
        $this->color(Color::Danger);
        $this->requiresConfirmation();
        $this->modalHeading(fn (): string => __('alfakit::actions.detach.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.detach.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.detach.notification'));

        $this->action(function (Model $record): void {
            $context = $this->getContext();
            $relationship = $context instanceof RelationManager ? $context->getRelationship() : null;

            if (! $relationship instanceof BelongsToMany) {
                throw new RuntimeException('DetachAction can only be used with BelongsToMany relationships inside a relation manager.');
            }

            $relationship->detach($record->getKey());
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
