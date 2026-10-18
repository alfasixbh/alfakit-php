<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use AlfaSix\Alfakit\Resources\ApiResource;
use Illuminate\Database\Eloquent\Model;

/**
 * The create page of an `ApiResource`: the validated form data goes to the data
 * source's `create()`. API validation errors (422) land on the form fields; other
 * failures show an error notification and keep the form as filled in.
 */
class CreateApiRecord extends CreateRecord
{
    /**
     * @return class-string<ApiResource>
     */
    public static function getResource(): string
    {
        /** @var class-string<ApiResource> */
        return static::$resource;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, bool $another = false): mixed
    {
        $this->callHook('beforeCreate', [$data]);

        $data = $this->mutateFormDataBeforeCreate($data);

        // No database transaction and no relationships: the data source stores the record.
        $this->createdRecord = $this->handleRecordCreation($data);

        $this->callHook('afterCreate');

        $this->getCreatedNotification()?->send();

        if ($another) {
            return redirect()->to(static::getResource()::getUrl('create'));
        }

        return redirect()->to($this->getRedirectUrl());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = static::getResource()::getDataSource()->makeRecord(exists: false);
        $record->persist($data);

        return $record;
    }

    public function getCreatedRecord(): ?ApiRecord
    {
        return $this->createdRecord instanceof ApiRecord ? $this->createdRecord : null;
    }
}
