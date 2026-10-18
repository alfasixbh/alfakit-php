<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exceptions;

use Exception;

/**
 * Thrown by `$action->cancel()` to stop the action and close its modal.
 */
class Cancel extends Exception {}
