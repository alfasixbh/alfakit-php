<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

class DateTimePicker extends DatePicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hasTime = true;
    }
}
