<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateApiRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\CarrierResource;

class CreateCarrier extends CreateApiRecord
{
    protected static string $resource = CarrierResource::class;
}
