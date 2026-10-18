<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsMorph;

use AlfaSix\Alfakit\Forms\Components\MorphToSelect;
use AlfaSix\Alfakit\Forms\Components\MorphToSelect\Type;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A standalone create form with a MorphToSelect. */
class NoteForm extends Form
{
    public function form(Schema $schema): Schema
    {
        return $schema->model(Note::class)->components([
            MorphToSelect::make('notable')->required()->types([Type::make(Product::class)->titleAttribute('name')]),
        ]);
    }

    public function submit(array $data): mixed
    {
        return Note::query()->create($data);
    }
}
