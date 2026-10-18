<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionLifecycle;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Pages\Page;
use Closure;

/**
 * A page whose header actions each test sets (`LifecyclePage::$actions`), for the action
 * lifecycle, authorisation feedback and menu action tests.
 */
class LifecyclePage extends Page
{
    protected static ?string $slug = 'lifecycle';

    /** @var (Closure(): array<Action>)|null */
    public static ?Closure $actions = null;

    /** @var array<int, mixed> */
    public static array $runs = [];

    protected function getHeaderActions(): array
    {
        return static::$actions === null ? [] : (static::$actions)();
    }
}
