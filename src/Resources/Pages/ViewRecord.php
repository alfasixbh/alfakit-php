<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Resources\Pages\Concerns\CanRefreshFormData;
use AlfaSix\Alfakit\Resources\Pages\Concerns\HasRelationManagers;
use AlfaSix\Alfakit\Resources\Pages\Concerns\InteractsWithRecord;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedRelationManagers;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedSchema;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Serializable;

class ViewRecord extends Page
{
    use CanRefreshFormData;
    use HasRelationManagers;
    use InteractsWithRecord;

    public function mount(mixed ...$parameters): void
    {
        $this->record = $this->resolveRecord($parameters[0] ?? null);

        abort_unless(static::getResource()::canView($this->record), 403);
    }

    /**
     * Adds the record's presence channel (`realtime()`): who else is on the record.
     *
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        $panel = $this->getPanel();

        return Serializable::compact([
            ...parent::toProps(),
            'presence' => $panel->getRecordPresenceSchema($this->record, 'viewing', $panel->auth()->user()),
        ]);
    }

    public function getTitle(): string
    {
        return static::$title ?? (string) ($this->getRecordTitle() ?? __('alfakit::panel.resources.view.title', ['label' => static::getResource()::getModelLabel()]));
    }

    public function getBreadcrumb(): string
    {
        return static::$breadcrumb ?? (string) $this->getRecordTitle();
    }

    public function infolist(Schema $schema): Schema
    {
        return static::getResource()::infolist($schema);
    }

    /**
     * Falls back to the resource form, rendered disabled, when no infolist is defined.
     */
    public function form(Schema $schema): Schema
    {
        if ($this->getInfolist() !== null) {
            return $schema;
        }

        $schema = static::getResource()::form($schema->operation('view'));

        foreach ($schema->getFlatComponents(withHidden: true) as $component) {
            $component->disabled();
        }

        return $schema;
    }

    /** Runs when the page loads, before the (disabled) form is filled from the record. */
    protected function beforeFill(): void {}

    /** Runs when the page loads, after the (disabled) form was filled from the record. */
    protected function afterFill(): void {}

    protected function getFormOperation(): string
    {
        return 'view';
    }

    /**
     * The infolist (or the disabled form), and the relation managers, with any `content()`
     * components around them.
     */
    protected function getContentBlocks(): array
    {
        $infolist = $this->getInfolist();
        $form = $infolist === null ? $this->getForm() : null;

        return $this->composeContentBlocks($this->combineRelationManagerTabsWithContent([
            'infolist' => $infolist === null ? null : $this->serializeInfolist($infolist),
            'form' => $form === null ? null : ['type' => 'Form', 'key' => 'form', 'component' => 'form', ...$form->toPayload(), 'operation' => 'view', 'actions' => []],
            'relationManagers' => $this->serializeRelationManagers(),
        ], $infolist === null ? 'form' : 'infolist'));
    }

    /**
     * Places the infolist inside `content()`.
     */
    public function getInfolistContentComponent(): EmbeddedSchema
    {
        return EmbeddedSchema::make('infolist');
    }

    /**
     * Places the disabled form (pages without an infolist) inside `content()`.
     */
    public function getFormContentComponent(): EmbeddedSchema
    {
        return EmbeddedSchema::make('form');
    }

    /**
     * Places the relation managers inside `content()`.
     */
    public function getRelationManagersContentComponent(): EmbeddedRelationManagers
    {
        return EmbeddedRelationManagers::make();
    }
}
