<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Infolist;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class PostDetails extends Infolist
{
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('title'), TextEntry::make('status')]);
    }

    public function actions(): array
    {
        return [Action::make('publish')->action(fn (Post $record) => $record->update(['status' => 'published']))];
    }
}
