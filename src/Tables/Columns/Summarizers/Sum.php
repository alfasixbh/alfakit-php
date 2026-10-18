<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Summarizers;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The total of a numeric column: `TextColumn::make('price')->summarize(Sum::make()->money())`.
 */
class Sum extends Summarizer
{
    protected function getDefaultLabel(): ?string
    {
        return __('alfakit::tables.summaries.sum');
    }

    protected function summarize(QueryBuilder $query, string $attribute): mixed
    {
        return $query->sum($attribute);
    }
}
