<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Schemas;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Fragment;

class AddressFields extends Fragment
{
    public function __construct(private readonly bool $withCity = true)
    {
        parent::__construct();
    }

    public function define(): array
    {
        return array_values(array_filter([
            TextInput::make('street')->required(),
            $this->withCity ? TextInput::make('city') : null,
        ]));
    }
}
