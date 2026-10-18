<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasDescription;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;
use AlfaSix\Alfakit\Support\Enums\Color;

enum PostStatus: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft copy',
            self::Published => 'Live',
            self::Archived => 'Archived',
        };
    }

    public function getColor(): Color|string|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => Color::Success,
            self::Archived => null,
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'pencil',
            self::Published => 'circle-check',
            self::Archived => null,
        };
    }

    public function getDescription(): ?string
    {
        return $this === self::Published ? 'Visible to everyone' : null;
    }
}
