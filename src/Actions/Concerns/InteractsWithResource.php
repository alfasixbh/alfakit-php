<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithResource
{
    protected bool $isOfferedBeforeRecord = false;

    /**
     * The action is shown before its record is known and gets it when it opens (a calendar
     * event's click action): until then it is authorised like the resource's records
     * (`viewAny`), and the record's own ability is checked when it opens or runs. Record
     * actions (edit, delete) without this stay hidden without a record.
     */
    public function offeredBeforeRecord(bool $condition = true): static
    {
        $this->isOfferedBeforeRecord = $condition;

        return $this;
    }

    /**
     * @return class-string<resource>|null
     */
    protected function getResource(): ?string
    {
        $context = $this->getContext();

        return $context instanceof HasResource ? $context->getResourceClass() : null;
    }

    protected function getResourceUrl(string $page, ?Model $record = null): ?string
    {
        $resource = $this->getResource();

        if ($resource === null || ! $resource::hasPage($page)) {
            return null;
        }

        return $resource::getUrl($page, $record !== null ? ['record' => $record] : []);
    }

    protected function resourceAuthorizes(string $ability, ?Model $record = null): bool
    {
        $resource = $this->getResource();

        // Without a resource (relation managers, custom pages) the model's policy still
        // applies.
        if ($resource === null) {
            return \AlfaSix\Alfakit\Support\Authorization::allows($ability, $record ?? $this->getModel());
        }

        return $resource::authorizes($ability, $record);
    }
}
