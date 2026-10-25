<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\ToggleColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

class ArticleResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'articles';

    protected static ?string $recordTitleAttribute = 'title';

    /** Records seen by the chunked bulk action, for assertions. */
    public static array $touched = [];

    public static bool $sawLazyCollection = false;

    /** Adds an editable toggle column and a wizard record action (list tab requests). */
    public static bool $withTabRequests = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                ...(static::$withTabRequests ? [ToggleColumn::make('is_published')] : []),
            ])
            ->recordActions([
                ...(static::$withTabRequests ? [
                    Action::make('onboard')->steps([
                        Step::make('Account')->key('account')->schema([TextInput::make('email')->required()]),
                        Step::make('Profile')->key('profile')->schema([TextInput::make('bio')]),
                    ])->action(fn () => null),
                ] : []),
                EditAction::make()
                    ->url(null)
                    ->extraModalFooterActions([
                        Action::make('preview')
                            ->schema([TextInput::make('note')->required()])
                            ->action(fn (Model $record, array $data) => Notification::make()->title("preview {$record->getKey()} {$data['note']}")->send()),
                        Action::make('publish')
                            ->cancelParentActions()
                            ->action(fn (Model $record) => $record->update(['is_published' => true])),
                    ]),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
                BulkAction::make('touch')
                    ->chunkSelectedRecords(2)
                    ->action(function (Enumerable $records): void {
                        static::$sawLazyCollection = $records instanceof LazyCollection;

                        foreach ($records as $record) {
                            static::$touched[] = $record->getKey();
                            $record->delete();
                        }
                    }),
                BulkAction::make('mark')
                    ->authorizeIndividualRecords(fn (Post $record): bool => $record->rating !== 0)
                    ->extraModalFooterActions([
                        BulkAction::make('count')->action(fn (Enumerable $records) => Notification::make()->title('count '.$records->count())->send()),
                    ])
                    ->action(fn (Enumerable $records) => $records->each(fn (Post $record) => $record->update(['status' => 'marked']))),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Discussion', [
                ArticleResource\RelationManagers\CommentsRelationManager::class,
                ArticleResource\RelationManagers\TagsRelationManager::class,
            ])->icon('message-circle')->badge(fn (Post $ownerRecord): int => $ownerRecord->comments()->count()),
        ];
    }

    public static function getGlobalSearchResultActions(Model $record): array
    {
        return [
            Action::make('comments')->url(static::getUrl('comments', ['record' => $record])),
            Action::make('server')->action(fn () => null),
            Action::make('hidden')->url('/x')->hidden(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ArticleResource\Pages\ListArticles::route('/'),
            'create' => ArticleResource\Pages\CreateArticle::route('/create'),
            'edit' => ArticleResource\Pages\EditArticle::route('/{record}/edit'),
            'comments' => ArticleResource\Pages\ManageArticleComments::route('/{record}/discussion'),
        ];
    }
}
