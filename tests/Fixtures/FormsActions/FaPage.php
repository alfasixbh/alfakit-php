<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsActions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\LocaleSwitcher;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class FaPage extends Page
{
    protected static ?string $slug = 'tools';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    /** Steps whose validation hooks ran, in order. */
    public static array $stepHooks = [];

    public function form(Schema $schema): Schema
    {
        return $schema->model(Post::class)->components([
            TextInput::make('title')->translatable(),
            MarkdownEditor::make('content')->translatable(),
            FileUpload::make('attachment')->storeFiles(false),
            ModalTableSelect::make('post_ids')
                ->multiple()
                ->recordTitleAttribute('title')
                ->tableConfiguration(fn (Table $table): Table => $table
                    ->query(Post::query())
                    ->columns([TextColumn::make('title')])
                    ->filters([SelectFilter::make('author')->relationship('author', 'name')->searchable()])),
            Wizard::make([
                Step::make('Basics')->key('basics')->schema([TextInput::make('name')->required()]),
                Step::make('Details')->key('details')->schema([TextInput::make('bio')->required()->maxLength(5)]),
            ])->key('wizard'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
            Action::make('attach')
                ->form([FileUpload::make('file')->storeFiles(false)->required()])
                ->action(function (array $data): void {
                    static::$runs[] = ['attach' => $data];
                }),
            Action::make('onboard')
                ->steps([
                    Step::make('Account')
                        ->key('account')
                        ->beforeValidation(function (): void {
                            static::$stepHooks[] = 'account:before';
                        })
                        ->afterValidation(function (): void {
                            static::$stepHooks[] = 'account:after';
                        })
                        ->schema([TextInput::make('name')->required()]),
                    Step::make('Profile')->key('profile')->schema([TextInput::make('bio')->required()]),
                ])
                ->action(function (array $data): void {
                    static::$runs[] = ['onboard' => $data];
                }),
        ];
    }
}
