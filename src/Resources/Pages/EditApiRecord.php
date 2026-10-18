<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use AlfaSix\Alfakit\Resources\Pages\Concerns\InteractsWithApiRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The edit page of an `ApiResource`: the form fills from the record and saves through
 * the data source's `update()` (with the whole validated form data). API validation
 * errors (422) land on the form fields; other failures show an error notification.
 */
class EditApiRecord extends EditRecord
{
    use InteractsWithApiRecord;

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, bool $shouldRedirect = true): mixed
    {
        $this->callHook('beforeSave', [$data]);

        $data = $this->mutateFormDataBeforeSave($data);

        // No database transaction and no relationships: the data source stores the record.
        $this->record = $this->handleRecordUpdate($this->record ?? abort(404), $data);

        $this->callHook('afterSave');

        $this->getSavedNotification()?->send();

        if (! $shouldRedirect) {
            return null;
        }

        return redirect()->to($this->getRedirectUrl());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record instanceof ApiRecord) {
            $record->persist($data);

            return $record;
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
