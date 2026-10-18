<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources\Pages;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Resources\Pages\EditApiRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\SupplierResource;

class EditSupplier extends EditApiRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
