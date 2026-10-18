<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery;

use AlfaSix\Alfakit\Widgets\Widget;

class HiddenDiscoveryWidget extends Widget
{
    protected static bool $isDiscovered = false;
}
