<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Show / hide soft-deleted records. On a model without SoftDeletes the filter leaves the
 * query alone instead of calling the missing trashed scopes.
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
            true: static fn (Builder $query): Builder => self::softDeletes($query) ? $query->withTrashed() : $query,
            false: static fn (Builder $query): Builder => self::softDeletes($query) ? $query->onlyTrashed() : $query,
            blank: static fn (Builder $query): Builder => self::softDeletes($query) ? $query->withoutTrashed() : $query,
        );
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected static function softDeletes(Builder $query): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true);
    }
}
