<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Support\Enums\ActionVariant;

/**
 * A dropdown of bulk actions, shown when records are selected:
 *
 * ```php
 * $table->bulkActions([
 *     BulkActionGroup::make([DeleteBulkAction::make(), ExportBulkAction::make()]),
 * ])
 * ```
 *
 * Unlike a plain `ActionGroup` (an icon button), it is a labelled button ("Bulk actions").
 * `$table->groupedBulkActions([...])` wraps every bulk action in one.
 */
class BulkActionGroup extends ActionGroup
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::tables.selection.bulk_actions'));
        $this->icon('alfakit::actions.group');
        $this->variant(ActionVariant::Outline);
    }
}
