<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A form scoped to a tenant (`TeamForm::make(['tenant' => $team])`). */
class TeamForm extends Form
{
    /** @var array<int, array<string, mixed>> */
    public static array $submitted = [];

    public static function requiresAuthentication(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->live(),
        ]);
    }

    public function submit(array $data): mixed
    {
        static::$submitted[] = [...$data, 'tenant' => alfakit()->getTenant()?->getKey()];

        return back();
    }
}
