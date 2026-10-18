<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListApiRecords;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\CarrierResource;

class ListCarriers extends ListApiRecords
{
    protected static string $resource = CarrierResource::class;
}
