<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RtCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static bool $isLazy = false;

    protected static ?string $badgeTooltip = 'Comments on this post';

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make('Discussion')
            ->icon('message-circle')
            ->badge(fn (): int => $ownerRecord->comments()->count())
            ->badgeColor('warning');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('author_name')])
            ->headerActions([CreateAction::make()]);
    }
}
