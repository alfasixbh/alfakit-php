<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A form only signed-in users may use. */
class SecureForm extends Form
{
    public static bool $allow = true;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function authorize(): bool
    {
        return static::$allow;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public function submit(array $data): mixed
    {
        return back();
    }
}
