<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Icons;

use AlfaSix\Alfakit\Forms\Components\IconPicker;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;

/**
 * A page whose form holds IconPicker fields, for the icon search endpoint tests.
 */
class IconPickerPage extends Page
{
    protected static ?string $slug = 'icon-picker';

    protected static ?string $title = 'Icon picker';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            IconPicker::make('icon')->perPage(5),
            IconPicker::make('demo_icon')->sets(['demo'])->exclude(['demo-arrow']),
            IconPicker::make('allowed_icon')->allowedIcons(['house', 'demo-home'])->allowCustomSvg(),
            IconPicker::make('locked_icon')->disabled(),
            IconPicker::make('hidden_icon')->hidden(),
        ]);
    }
}
