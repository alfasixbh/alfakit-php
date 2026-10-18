<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Support\Str;

/** A guest create form with a BelongsToMany select: `submit()` returns the new record. */
class PostWithTagsForm extends Form
{
    public function form(Schema $schema): Schema
    {
        return $schema->model(Post::class)->components([
            TextInput::make('title')->required(),
            Select::make('tags')->relationship('tags', 'name')->multiple(),
        ]);
    }

    public function submit(array $data): mixed
    {
        return Post::query()->create([...$data, 'slug' => Str::slug((string) $data['title'])]);
    }
}
