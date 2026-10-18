<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

class Hidden extends Field
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
