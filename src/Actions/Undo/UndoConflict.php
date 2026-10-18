<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Undo;

use RuntimeException;

/**
 * An undo that can't be applied (the record changed since, or is gone). Its message is
 * shown to the user as a notification.
 */
final class UndoConflict extends RuntimeException {}
