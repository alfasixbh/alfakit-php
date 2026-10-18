<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;

class RestoreAction extends Action
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
        $this->modalHeading(fn (): string => __('alfakit::actions.restore.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.restore.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.restore.notification'));

        $this->authorize(fn (?Model $record): bool => $this->resourceAuthorizes('restore', $record));
        $this->visible(fn (?Model $record): bool => $record !== null && method_exists($record, 'trashed') && $record->trashed());
        $this->action(fn (Model $record) => $record->restore());
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
