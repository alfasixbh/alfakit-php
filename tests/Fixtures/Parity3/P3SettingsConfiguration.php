<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3;

use AlfaSix\Alfakit\Pages\PageConfiguration;

class P3SettingsConfiguration extends PageConfiguration
{
    protected ?string $category = null;

    public function category(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }
}
