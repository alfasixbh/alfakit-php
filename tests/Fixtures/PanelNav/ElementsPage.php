<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\EmptyState;
use AlfaSix\Alfakit\Schemas\Components\Flex;
use AlfaSix\Alfakit\Schemas\Components\Icon;
use AlfaSix\Alfakit\Schemas\Components\Image;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\UnorderedList;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use RuntimeException;

class ElementsPage extends Page
{
    protected static ?string $slug = 'elements';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Flex::make([
                Section::make('Main')->schema([Text::make('Body')]),
                Section::make('Side')->schema([Icon::make('star')->color('warning')])->grow(false),
            ])->from('md')->verticallyAlignCenter(),
            Callout::make('Heads up')
                ->description('<b>Plain</b> text')
                ->warning()
                ->actions([
                    Action::make('acknowledge')->action(fn () => Tag::query()->create(['name' => 'acknowledged'])),
                    Action::make('explode')->action(function (): void {
                        Tag::query()->create(['name' => 'rolled back']);

                        throw new RuntimeException('Boom');
                    }),
                    Action::make('explodeWithoutTransaction')->databaseTransaction(false)->action(function (): void {
                        Tag::query()->create(['name' => 'kept']);

                        throw new RuntimeException('Boom');
                    }),
                ]),
            EmptyState::make('Nothing here')->description('Create one')->actions([
                Action::make('create')->url('/nav/notes'),
            ]),
            Image::make('/chart.png', 'Chart')->imageHeight(120)->alignCenter(),
            UnorderedList::make(['First', Text::make('Second')->weight('bold')]),
        ]);
    }
}
