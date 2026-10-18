<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Show / hide soft-deleted records.
 */
class TrashedFilter extends TernaryFilter
{
    public static function make(string $name = 'trashed'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        $this->label(__('alfakit::tables.filters.trashed.label'));
        $this->placeholder(__('alfakit::tables.filters.trashed.without'));
        $this->trueLabel(__('alfakit::tables.filters.trashed.with'));
        $this->falseLabel(__('alfakit::tables.filters.trashed.only'));

        $this->queries(
            true: static fn (Builder $query): Builder => $query->withTrashed(),
            false: static fn (Builder $query): Builder => $query->onlyTrashed(),
            blank: static fn (Builder $query): Builder => $query->withoutTrashed(),
        );
    }
}
