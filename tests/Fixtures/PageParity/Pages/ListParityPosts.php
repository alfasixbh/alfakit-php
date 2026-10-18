<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPostResource;
use Illuminate\Database\Eloquent\Builder;

class ListParityPosts extends ListRecords
{
    protected static string $resource = ParityPostResource::class;

    public static bool $excludeDrafts = true;

    public static int $badgeEvaluations = 0;

    public static ?string $defaultActionName = null;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'drafts' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->excludeQueryWhenResolvingRecord(static::$excludeDrafts),
            'published' => Tab::make('Published')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'published'))
                ->badge(function (): int {
                    static::$badgeEvaluations++;

                    return Post::query()->where('status', 'published')->count();
                })
                ->badgeColor('success')
                ->deferBadge()
                ->extraAttributes(['data-tab' => 'published', 'class' => 'font-bold']),
        ];
    }

    public function getDefaultAction(): ?string
    {
        return static::$defaultActionName;
    }
}
