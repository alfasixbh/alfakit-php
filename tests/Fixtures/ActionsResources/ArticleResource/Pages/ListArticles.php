<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Builder;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    /** The tab shown without `?activeTab=` (null: the first one). */
    public static ?string $defaultTab = null;

    public function getDefaultActiveTab(): string|int|null
    {
        return static::$defaultTab ?? parent::getDefaultActiveTab();
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'published' => Tab::make()
                ->icon('check')
                ->badge(fn (): int => Post::query()->where('is_published', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', true)),
            'drafts' => Tab::make('Drafts')->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false)),
            'secret' => Tab::make('Secret')->hidden()->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('1 = 0')),
        ];
    }
}
