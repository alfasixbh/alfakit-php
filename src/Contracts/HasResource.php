<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

use AlfaSix\Alfakit\Resources\Resource;

/**
 * Implemented by contexts (pages, tables, relation managers) that belong to a Resource,
 * so prebuilt actions can resolve URLs and authorisation.
 */
interface HasResource
{
    /**
     * @return class-string<resource>|null
     */
    public function getResourceClass(): ?string;
}
