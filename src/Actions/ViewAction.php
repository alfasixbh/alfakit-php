<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;

class ViewAction extends Action
{
    use InteractsWithResource;

    public static function make(string $name = 'view'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.view.label'));
        $this->icon('alfakit::actions.view');
        $this->color(Color::Gray);
        $this->modalHeading(fn (): string => __('alfakit::actions.view.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitAction(false);
        $this->modalCancelActionLabel(__('alfakit::actions.modal.close'));

        $this->url(fn (?Model $record): ?string => $this->hasForm() || $this->modalContent !== null || $record === null ? null : $this->getResourceUrl('view', $record));

        // Before its record is known (a calendar event, a Kanban card: the record is
        // resolved when the modal opens, and `view` is checked then), the action is offered
        // to users who may view the records at all.
        $this->authorize(fn (?Model $record): bool => $record === null
            ? $this->resourceAuthorizes('viewAny')
            : $this->resourceAuthorizes('view', $record));

        $this->modifyFormUsing(fn ($schema) => $schema->operation('view'));
        $this->disabledForm();
    }
}
