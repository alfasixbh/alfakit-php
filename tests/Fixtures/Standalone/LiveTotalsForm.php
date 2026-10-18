<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** Live fields that limit what their updates re-render. */
class LiveTotalsForm extends Form
{
    public function form(Schema $schema): Schema
    {
        $echo = fn (string $name): TextInput => TextInput::make($name)->helperText(fn (Get $get): string => 'Quantity '.$get('quantity'));

        return $schema->components([
            TextInput::make('quantity')->live()
                ->afterStateUpdated(fn (Set $set, mixed $state) => $set('total', (int) $state * 10))
                ->partiallyRenderComponentsAfterStateUpdated(['total']),
            $echo('total'),
            Section::make('Notes')->key('notes')->schema([$echo('note')]),
            TextInput::make('silent')->live()
                ->afterStateUpdated(fn (Set $set, mixed $state) => $set('total', 1))
                ->skipRenderAfterStateUpdated(),
            TextInput::make('self')->live()->partiallyRenderAfterStateUpdated()->helperText(fn (Get $get): string => 'Self '.$get('self')),
            TextInput::make('quiet')->live()->skipRenderAfterStateUpdated(),
            Repeater::make('lines')->schema([
                TextInput::make('qty')->live()->partiallyRenderComponentsAfterStateUpdated(['amount']),
                TextInput::make('amount')->helperText(fn (Get $get): string => 'Qty '.$get('qty')),
            ])->default([['qty' => 1, 'amount' => 1]]),
        ]);
    }
}
