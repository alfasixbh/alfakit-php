<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;

/** Needs a subscription even when the panel doesn't. */
class P3PaidPage extends Page
{
    protected static ?string $slug = 'paid';

    public static function isTenantSubscriptionRequired(Panel $panel): bool
    {
        return true;
    }
}
