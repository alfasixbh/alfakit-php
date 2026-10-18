<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets\ChartWidget\Concerns;

use AlfaSix\Alfakit\Schemas\Schema;

/**
 * A form of filters on a chart widget instead of a single select (Filament's
 * `HasFiltersSchema`):
 *
 * ```php
 * use HasFiltersSchema;
 *
 * public function filtersSchema(Schema $schema): Schema
 * {
 *     return $schema->components([
 *         DatePicker::make('startDate')->default(now()->subDays(30)),
 *         DatePicker::make('endDate')->default(now()),
 *     ]);
 * }
 *
 * protected function getData(): array
 * {
 *     $start = $this->filters['startDate'] ?? null;
 *     // ...
 * }
 * ```
 *
 * The values live in the query string (`chartFilters[{widget id}][startDate]`); values that
 * fail the fields' validation fall back to the defaults. With `$hasDeferredFilters` the
 * chart only updates when the user clicks "Apply".
 */
trait HasFiltersSchema
{
    public function filtersSchema(Schema $schema): Schema
    {
        return $schema;
    }

    protected function makeFiltersSchema(): ?Schema
    {
        $schema = $this->filtersSchema(Schema::make()->operation('create'));

        return $schema->getComponents(withHidden: true) === [] ? null : $schema;
    }
}
