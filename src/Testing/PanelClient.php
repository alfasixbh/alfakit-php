<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;

/**
 * Sends requests through the application's HTTP kernel, like a feature test does. The
 * authenticated user, the session and exception handling are the test's own, because
 * they live in the same application container.
 *
 * @internal
 */
final class PanelClient
{
    use MakesHttpRequests;

    protected Application $app;

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? app();
    }
}
