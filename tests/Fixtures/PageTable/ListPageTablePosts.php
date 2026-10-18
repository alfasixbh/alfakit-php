<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Pages\Concerns\ExposesTableToWidgets;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPageTablePosts extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = PageTablePostResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(),
            'published' => Tab::make()->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', true)),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PostTableStats::class,
            LazyPostTableStats::class,
            StatusCountWidget::make(['status' => 'draft']),
            StatusCountWidget::make(['status' => 'published']),
        ];
    }
}
