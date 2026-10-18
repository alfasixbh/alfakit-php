<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A guest contact form rendered in a host Inertia page. */
class ContactForm extends Form
{
    /** @var array<int, array<string, mixed>> */
    public static array $submitted = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required(),
            Textarea::make('message')->required()->minLength(3),
        ]);
    }

    public function submit(array $data): mixed
    {
        static::$submitted[] = $data;

        Notification::make()->success()->title('Thanks!')->send();

        return back();
    }
}
