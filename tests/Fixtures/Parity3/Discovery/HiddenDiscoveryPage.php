<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery;

use AlfaSix\Alfakit\Pages\Page;

class HiddenDiscoveryPage extends Page
{
    protected static bool $isDiscovered = false;
}
