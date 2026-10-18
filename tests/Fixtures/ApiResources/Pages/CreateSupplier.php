<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateApiRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\SupplierResource;

class CreateSupplier extends CreateApiRecord
{
    protected static string $resource = SupplierResource::class;
}
