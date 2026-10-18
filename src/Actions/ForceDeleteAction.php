<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;

class ForceDeleteAction extends Action
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
        $this->modalHeading(fn (): string => __('alfakit::actions.force_delete.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalDescription(__('alfakit::actions.force_delete.modal.description'));
        $this->modalSubmitActionLabel(__('alfakit::actions.force_delete.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.force_delete.notification'));

        $this->authorize(fn (?Model $record): bool => $this->resourceAuthorizes('forceDelete', $record));
        $this->visible(fn (?Model $record): bool => $record !== null && method_exists($record, 'trashed') && $record->trashed());
        $this->action(fn (Model $record) => $record->forceDelete());
        $this->successRedirectUrl(function (?Model $record): ?string {
            $context = $this->getContext();

            if ($record !== null && is_object($context) && method_exists($context, 'getRecord') && $context->getRecord()?->is($record)) {
                return $this->getResourceUrl('index');
            }

            return null;
        });
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
