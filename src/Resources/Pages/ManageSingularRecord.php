<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource that manages one record (site settings, the current team's profile…): the
 * resource's only page edits that record, creating it on the first save.
 *
 * ```php
 * public static function getPages(): array
 * {
 *     return ['index' => Pages\ManageSettings::route('/')];
 * }
 * ```
 *
 * The record is the first one in the resource's (tenant-scoped) query; override
 * `resolveSingularRecord()` to pick it differently. Saving needs `update` on an existing
 * record and `create` on a new one.
 */
class ManageSingularRecord extends EditRecord
{
    public static function canAccess(): bool
    {
        return static::getResource()::canViewAny();
    }

    public function mount(mixed ...$parameters): void
    {
        $this->record = $this->resolveSingularRecord();

        $resource = static::getResource();

        abort_unless($this->record->exists ? $resource::canEdit($this->record) : $resource::canCreate(), 403);
    }

    /**
     * The record this page manages, or a new instance when there is none yet.
     */
    protected function resolveSingularRecord(): Model
    {
        $resource = static::getResource();
        $model = $resource::getModel();

        return $resource::getEloquentQuery()->first() ?? new $model;
    }

    public function getTitle(): string
    {
        return static::$title ?? \AlfaSix\Alfakit\Support\Html::toText(static::getResource()::getNavigationLabel());
    }

    public static function getNavigationLabel(): string
    {
        return \AlfaSix\Alfakit\Support\Html::toText(static::getResource()::getNavigationLabel());
    }

    public function getBreadcrumbs(): array
    {
        return [...$this->getParentBreadcrumbs(), $this->getTitle()];
    }

    public function getRouteParameters(): array
    {
        // The record is found by the page itself, never from the URL.
        $parameters = parent::getRouteParameters();
        unset($parameters['record']);

        return $parameters;
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->hidden();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data);

        if ($record->exists) {
            $record->save();

            return $record;
        }

        $attachToParent = static::getResource()::associateRecordWithParent($record);

        // A first save creates the record, owned by the current tenant.
        $record = static::getResource()::isScopedToTenant() ? TenantScope::associate($record) : tap($record)->save();

        if ($attachToParent) {
            static::getResource()::attachRecordToParent($record);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl(static::getResourcePageName() ?? 'index');
    }
}
