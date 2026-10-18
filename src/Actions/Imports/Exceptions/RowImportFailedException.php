<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Exceptions;

use RuntimeException;

/**
 * Throw from an importer (`resolveRecord()`, a hook, `fillRecordUsing`) to skip the row
 * and record the message in the failed-rows CSV.
 */
class RowImportFailedException extends RuntimeException {}
