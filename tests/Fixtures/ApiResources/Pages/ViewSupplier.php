<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources\Pages;

use AlfaSix\Alfakit\Resources\Pages\ViewApiRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\SupplierResource;

class ViewSupplier extends ViewApiRecord
{
    protected static string $resource = SupplierResource::class;
}
