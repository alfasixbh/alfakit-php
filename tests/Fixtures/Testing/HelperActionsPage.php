<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Testing;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;

class HelperActionsPage extends Page
{
    protected static ?string $slug = 'helper-actions';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rename')
                ->form([
                    TextInput::make('name')->required()->maxLength(20)
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', str($state ?? '')->slug()->toString())),
                    TextInput::make('slug'),
                ])
                ->fillForm(['name' => 'Default'])
                ->action(function (array $data): void {
                    static::$runs[] = ['rename' => $data];
                    Notification::make()->success()->title('Renamed')->body('All good')->send();
                }),
            Action::make('translate')
                ->form([TextInput::make('title')->required()->maxLength(10)->translatable(locales: ['en', 'ar'])])
                ->action(function (array $data): void {
                    static::$runs[] = ['translate' => $data];
                }),
            Action::make('ping')->action(function (array $arguments): void {
                static::$runs[] = ['ping' => $arguments];
            }),
            Action::make('stop')->action(fn (Action $action) => $action->halt()),
            Action::make('away')->action(fn () => redirect()->to('/helpers/elsewhere')),
            Action::make('secret')->hidden()->action(function (): void {
                static::$runs[] = ['secret' => true];
            }),
            Action::make('locked')->disabled()->action(fn () => null),
            Action::make('parent')
                ->requiresConfirmation()
                ->extraModalFooterActions([
                    Action::make('child')->action(function (): void {
                        static::$runs[] = ['child' => true];
                    }),
                ])
                ->action(fn () => null),
        ];
    }
}
