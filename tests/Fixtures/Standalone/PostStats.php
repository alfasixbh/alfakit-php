<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Standalone\Widgets;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Widgets\PostStatsWidget;

class PostStats extends Widgets
{
    public function widgets(): array
    {
        return [PostStatsWidget::class];
    }
}
