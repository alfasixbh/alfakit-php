<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A form that empties itself after submit (`reset()`, or `resetAfterSubmit()`). */
class ResettingForm extends Form
{
    public static bool $resetInSubmit = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('country')->default('NL'),
        ]);
    }

    public function submit(array $data): mixed
    {
        if (static::$resetInSubmit) {
            $this->reset(['country' => 'BE']);
        }

        return back();
    }
}
