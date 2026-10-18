<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthKit;

use AlfaSix\Alfakit\AuthKit\Account\Profile;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The docs' example of a customised account section (an extra field).
 */
class AccountProfile extends Profile
{
    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            TextInput::make('locale')->maxLength(10),
        ]);
    }

    protected function save(Model&Authenticatable $user, array $data): void
    {
        $user->forceFill(['locale' => $data['locale'] ?? null]);

        parent::save($user, $data);
    }
}
