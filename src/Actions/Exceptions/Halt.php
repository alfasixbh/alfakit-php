<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exceptions;

use Exception;

/**
 * Thrown by `$action->halt()` to stop the action without closing its modal.
 */
class Halt extends Exception {}
