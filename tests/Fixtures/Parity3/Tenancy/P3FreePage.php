<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;

/** Open to unsubscribed tenants. */
class P3FreePage extends Page
{
    protected static ?string $slug = 'free';

    public static function isTenantSubscriptionRequired(Panel $panel): bool
    {
        return false;
    }
}
