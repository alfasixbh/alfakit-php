<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\AuthKit\Concerns\InteractsWithAuthKit;
use AlfaSix\Alfakit\Standalone\Component;

/**
 * A page of the app auth kit (sign-in, registration…): a standalone component served by
 * the auth kit panel. Extend one of the defaults and register it with
 * `AuthKitPanel::components()` to change it.
 */
abstract class AuthKitComponent extends Component
{
    use InteractsWithAuthKit;
}
