<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Enums;

/**
 * How a table pages through its records.
 *
 * - `Default`: numbered pages with a total (one `count(*)` query per page).
 * - `Simple`: previous / next only, no count query.
 * - `Cursor`: previous / next with a cursor instead of an offset; stable and fast on
 *   large tables, sorted by plain columns only.
 * - `LoadMore`: a "Load more" button appends the next page.
 * - `InfiniteScroll`: the next page is appended when the end of the table scrolls into view.
 */
enum PaginationMode: string
{
    case Default = 'default';
    case Simple = 'simple';
    case Cursor = 'cursor';
    case LoadMore = 'load-more';
    case InfiniteScroll = 'infinite';

    /**
     * Load-more and infinite-scroll tables return every record up to the current page.
     */
    public function isAppending(): bool
    {
        return $this === self::LoadMore || $this === self::InfiniteScroll;
    }
}
