<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * A configurable resource: registered plain and as `make('archived')` / `make('drafts')`.
 */
class RtArchiveResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'entries';

    protected static ?string $navigationLabel = 'Entries';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $configurationClass = RtArchiveConfiguration::class;

    public static function getNavigationLabel(): string
    {
        $configuration = static::getConfiguration();

        return $configuration instanceof RtArchiveConfiguration && $configuration->getNavigationLabel() !== null
            ? $configuration->getNavigationLabel()
            : parent::getNavigationLabel();
    }

    public static function getEloquentQuery(): Builder
    {
        $configuration = static::getConfiguration();
        $query = parent::getEloquentQuery();

        return $configuration instanceof RtArchiveConfiguration && $configuration->getStatus() !== null
            ? $query->where('status', $configuration->getStatus())
            : $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRtEntries::route('/'),
            'edit' => Pages\EditRtEntry::route('/{record}/edit'),
        ];
    }
}
