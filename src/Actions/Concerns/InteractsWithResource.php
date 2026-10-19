<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithResource
{
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
