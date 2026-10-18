<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditApiRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\CarrierResource;

class EditCarrier extends EditApiRecord
{
    protected static string $resource = CarrierResource::class;
}
