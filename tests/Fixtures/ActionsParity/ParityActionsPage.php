<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsParity;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\SlideOverPosition;

class ParityActionsPage extends Page
{
    protected static ?string $slug = 'parity-actions';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->form([
                    TextInput::make('title')->required(),
                    TextInput::make('author'),
                ])
                ->fillForm(['title' => 'Draft'])
                ->stickyModalHeader()
                ->slideOver()
                ->slideOverPosition(SlideOverPosition::Start)
                ->modalFooterActionsAlignment(Alignment::Start)
                ->extraModalWindowAttributes(['class' => 'edit-modal'])
                ->extraModalWindowAttributes(['data-test' => 'edit'], merge: true)
                ->extraModalOverlayAttributes(fn (): array => ['class' => 'backdrop'])
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('saveAndPublish', arguments: ['publish' => true])->label('Save & publish'),
                    Action::make('peek')->action(function (array $mountedActions): void {
                        static::$runs[] = ['peek' => [count($mountedActions), $mountedActions[0]->getRawData()['title'] ?? null]];
                    }),
                    Action::make('check')->action(function (Action $action): void {
                        static::$runs[] = ['check' => $action->getParentAction()?->getValidatedData()];
                    }),
                    Action::make('suggest')
                        ->form([TextInput::make('name')])
                        ->overlayParentActions()
                        ->cancelParentActionsOnClose()
                        ->action(function (array $data, Action $action): void {
                            $action->getParentAction()?->fillData(['author' => $data['name'] ?? null, 'unknown' => 'x']);
                        }),
                    Action::make('discard')->cancelParentActions('edit')->action(fn () => null),
                    ActionGroup::make([
                        Action::make('archive')->action(function (Action $action): void {
                            static::$runs[] = ['archive' => $action->getParentAction()?->getName()];
                        }),
                        Action::make('secret')->hidden(),
                    ])->label('More'),
                ])
                ->action(function (array $data, array $arguments): void {
                    static::$runs[] = ['edit' => $data, 'arguments' => $arguments];
                }),
            Action::make('start')->action(function (Action $action): void {
                static::$runs[] = ['start' => true];
                $action->replaceMountedAction('finish', ['step' => 2]);
            }),
            Action::make('finish')
                ->requiresConfirmation()
                ->modalContent([Text::make('Almost done & dusted')])
                ->modalDescription(new \Illuminate\Support\HtmlString('Really <em>finish</em>?'))
                ->modalClickThrough()
                ->action(function (array $arguments): void {
                    static::$runs[] = ['finish' => $arguments];
                }),
            Action::make('docs')
                ->label('Documentation')
                ->icon('book')
                ->color('info')
                ->labeledFrom('md')
                ->url('https://example.com/docs', shouldOpenInNewTab: true),
            Action::make('inbox')->openDatabaseNotifications(),
            ActionGroup::make([
                Action::make('grid')->action(fn () => null),
                Action::make('list')->action(fn () => null),
            ])->buttonGroup()->label('View'),
            ActionGroup::make([
                ActionGroup::make([Action::make('copy')->action(fn () => null)])->dropdown(false),
                Action::make('archive')->action(fn () => null),
            ])->dropdownWidth('xs')->dropdownOffset(8)->maxHeight('200px'),
        ];
    }
}
