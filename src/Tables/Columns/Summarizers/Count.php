<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Summarizers;

use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The number of records, optionally narrowed:
 * `IconColumn::make('is_published')->summarize(Count::make()->query(fn (Builder $q) => $q->where('is_published', true)))`.
 *
 * On an `IconColumn`, `icons()` counts each icon instead:
 * `IconColumn::make('is_published')->boolean()->summarize(Count::make()->icons())`.
 */
class Count extends Summarizer
{
    protected bool|Closure $hasIcons = false;

    /**
     * Count the occurrences of each icon of an `IconColumn` (one count per icon and colour).
     */
    public function icons(bool|Closure $condition = true): static
    {
        $this->hasIcons = $condition;

        return $this;
    }

    public function hasIcons(): bool
    {
        return $this->getColumn() instanceof IconColumn && (bool) $this->evaluate($this->hasIcons);
    }

    protected function getDefaultLabel(): ?string
    {
        return __('alfakit::tables.summaries.count');
    }

    protected function summarize(QueryBuilder $query, string $attribute): mixed
    {
        if (! $this->hasIcons()) {
            return $query->count();
        }

        /** @var IconColumn $column */
        $column = $this->getColumn();
        $counts = [];

        $rows = $query->select($attribute)->selectRaw('count(*) as alfakit_count')->groupBy($attribute)->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $resolved = $column->getIconForState($row[$attribute] ?? null);

            if ($resolved['icon'] === null) {
                continue;
            }

            $key = $resolved['icon'].'|'.($resolved['color'] ?? '');
            $counts[$key] ??= ['icon' => $resolved['icon'], 'color' => $resolved['color'], 'count' => 0];
            $counts[$key]['count'] += (int) $row['alfakit_count'];
        }

        return array_values($counts);
    }

    public function toSummary(Builder $query): array
    {
        if (! $this->hasIcons()) {
            return parent::toSummary($query);
        }

        $state = $this->getState($query);

        /** @var list<array{icon: string, color: string|null, count: int}> $icons */
        $icons = is_array($state) ? $state : [];

        return $this->withLabel(['value' => null, 'icons' => $icons]);
    }
}
