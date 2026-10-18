<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\GuestAccess;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Pages\Page;

/** Needs a signed-in user even on a guest-access panel. */
class PrivatePage extends Page
{
    protected static ?string $slug = 'members';

    /** @var array<int, string> */
    public static array $runs = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ping')->action(function (): void {
                static::$runs[] = 'ping';
            }),
        ];
    }
}
