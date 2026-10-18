<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy;

use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;

/** Creates projects through `Team::projects()`. */
class P3ProjectResource extends ProjectResource
{
    protected static ?string $tenantRelationshipName = 'projects';
}
