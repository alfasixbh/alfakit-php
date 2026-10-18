<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tenancy\Pages\RegisterTenant;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Database\Eloquent\Model;

class RegisterTeam extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create team';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required(), TextInput::make('slug')->required()]);
    }

    protected function handleRegistration(array $data): Model
    {
        return Team::query()->create($data);
    }
}
