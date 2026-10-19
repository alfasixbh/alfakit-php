<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Brands\Pages;

use App\Filament\Clusters\Settings\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBrands extends ManageRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
