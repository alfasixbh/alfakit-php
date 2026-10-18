<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;

class ActionsPage extends Page
{
    protected static ?string $slug = 'actions';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    public static bool $hideParent = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('onboard')
                ->steps([
                    Step::make('Account')->schema([TextInput::make('name')->required()]),
                    Step::make('Profile')->schema([TextInput::make('bio')->required()]),
                ])
                ->skippableSteps()
                ->action(function (array $data): void {
                    static::$runs[] = ['onboard' => $data];
                }),
            Action::make('parent')
                ->requiresConfirmation()
                ->hidden(fn (): bool => static::$hideParent)
                ->extraModalFooterActions([
                    Action::make('child')
                        ->requiresConfirmation()
                        ->extraModalFooterActions([
                            Action::make('grandchild')->action(function (): void {
                                static::$runs[] = ['grandchild' => true];
                            }),
                        ])
                        ->action(function (): void {
                            static::$runs[] = ['child' => true];
                        }),
                ])
                ->registerModalActions([
                    Action::make('registered')->action(function (): void {
                        static::$runs[] = ['registered' => true];
                    }),
                ])
                ->action(fn () => null),
            Action::make('limitedForm')
                ->rateLimit(2)
                ->schema([TextInput::make('email')->required()->email()])
                ->action(function (array $data): void {
                    static::$runs[] = ['limitedForm' => $data['email']];
                }),
            Action::make('limited')
                ->rateLimit(2)
                ->action(function (): void {
                    static::$runs[] = ['limited' => true];
                }),
            Action::make('sensitive')
                ->requiresPasswordConfirmation()
                ->action(function (): void {
                    static::$runs[] = ['sensitive' => true];
                    Notification::make()->title('done')->send();
                }),
            Action::make('alwaysAsk')
                ->requiresPasswordConfirmation(rememberForMinutes: 0)
                ->action(function (): void {
                    static::$runs[] = ['alwaysAsk' => true];
                }),
        ];
    }
}
