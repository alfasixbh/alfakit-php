<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Undo;

/**
 * How an `undoable()` action is undone.
 */
enum UndoStrategy: string
{
    /** Soft-deleted records are restored (DeleteAction, DeleteBulkAction). */
    case Restore = 'restore';

    /** The record's changed attributes are put back (EditAction, the edit page's save). */
    case Snapshot = 'snapshot';

    /** The action's own `undoUsing()` closure runs. */
    case Custom = 'custom';
}
