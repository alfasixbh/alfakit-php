<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Summarizers;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The mean of a numeric column: `TextColumn::make('rating')->summarize(Average::make())`.
 */
class Average extends Summarizer
{
    protected function getDefaultLabel(): ?string
    {
        return __('alfakit::tables.summaries.average');
    }

    protected function summarize(QueryBuilder $query, string $attribute): mixed
    {
        return $query->avg($attribute);
    }
}
