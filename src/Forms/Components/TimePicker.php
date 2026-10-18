<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

class TimePicker extends DatePicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hasTime = true;
        $this->hasDate = false;
    }
}
