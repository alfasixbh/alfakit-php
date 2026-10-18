<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\GuestAccess;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\Schema;

/** A guest-accessible page with a wizard whose first step can halt. */
class PublicPage extends Page
{
    protected static ?string $slug = 'status';

    protected static bool $isGuestAccessible = true;

    /** @var array<int, string> */
    public static array $runs = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Contact')
                    ->key('contact')
                    // Full: tell the visitor and stay on the step.
                    ->beforeValidation(function (Get $get): void {
                        if ($get('email') === 'full@example.com') {
                            Notification::make()->warning()->title('Seats are full')->send();

                            throw new Halt;
                        }
                    })
                    ->schema([TextInput::make('email')->required()]),
                Step::make('Message')->key('message')->schema([TextInput::make('body')]),
            ])->key('wizard'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ping')->action(function (): void {
                static::$runs[] = 'ping';
            }),
        ];
    }
}
