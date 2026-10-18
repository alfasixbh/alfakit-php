<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Dashboard\Concerns\HasFiltersForm;
use AlfaSix\Alfakit\Schemas\Schema;

class FilteredDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static ?string $routePath = '/';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            TextInput::make('search')->maxLength(20),
        ]);
    }

    public function getWidgets(): array
    {
        return [FilteredPostsWidget::class];
    }
}
