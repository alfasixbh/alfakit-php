<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsActions;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Dashboard\Concerns\HasFiltersForm;
use AlfaSix\Alfakit\Schemas\Schema;

/** Dashboard filters with a live field (applied on change) and a plain one (Apply button). */
class FaDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static ?string $routePath = '/';

    /** Make every field live, so the Apply button disappears. */
    public static bool $onlyLive = false;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->live(),
            TextInput::make('search')->maxLength(5)->live(static::$onlyLive),
        ]);
    }

    public function getWidgets(): array
    {
        return [FaStatusWidget::class];
    }
}
