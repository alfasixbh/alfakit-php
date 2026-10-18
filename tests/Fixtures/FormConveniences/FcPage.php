<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormConveniences;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\Schema;

class FcPage extends Page
{
    protected static ?string $slug = 'checkout';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    public static bool $canPlaceOrder = true;

    public function form(Schema $schema): Schema
    {
        return $schema->inlineLabel()->components([
            Section::make('Account')->key('account')->inlineLabel(false)->schema([
                TextInput::make('email')->trim(),
                TextInput::make('api_key')->copyable(copyMessage: 'Key copied', copyMessageDuration: 1500)->inlineLabel(),
            ]),
            Wizard::make([
                Step::make('Cart')->key('cart')->schema([
                    TextInput::make('amount')->numeric()->stripCharacters(',')->required(),
                ]),
                Step::make('Pay')->key('pay')->completedIcon('star')->schema([TextInput::make('card')]),
            ])
                ->key('wizard')
                ->persistStepInQueryString('checkout-step')
                ->completedIcon('check-circle')
                ->nextAction(fn (Action $action) => $action->label('Continue')->icon('arrow-right'))
                ->previousAction(fn (Action $action) => $action->hidden())
                ->submitAction(
                    Action::make('placeOrder')
                        ->label('Place order')
                        ->authorize(fn (): bool => static::$canPlaceOrder)
                        ->action(function (): void {
                            static::$runs[] = ['placeOrder' => true];
                        }),
                ),
        ]);
    }
}
