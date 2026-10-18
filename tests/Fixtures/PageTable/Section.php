<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/** A navigation-group enum. */
enum Section implements HasIcon, HasLabel
{
    case Shop;
    case Content;

    public function getLabel(): string
    {
        return $this === self::Shop ? 'Shop floor' : 'Content';
    }

    public function getIcon(): string
    {
        return $this === self::Shop ? 'shopping-bag' : 'file-text';
    }
}
