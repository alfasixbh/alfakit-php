<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Events;

use AlfaSix\Alfakit\Panel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched on every panel request, after the panel and tenant are resolved.
 */
class ServingAlfakit
{
    use Dispatchable;

    public function __construct(public readonly Panel $panel) {}
}
