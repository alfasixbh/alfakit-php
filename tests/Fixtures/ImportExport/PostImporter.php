<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;

class PostImporter extends Importer
{
    protected static ?string $model = Post::class;

    /** @var array<string> */
    public static array $hooks = [];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')->requiredMapping()->rules(['required', 'max:100'])->example('Hello world'),
            ImportColumn::make('slug')->requiredMapping()->rules(['required', 'max:100'])->guess(['permalink'])->example('hello-world'),
            ImportColumn::make('author')->relationship(resolveUsing: 'email')->guess(['author email'])->example('ada@example.com'),
            ImportColumn::make('is_published')->boolean()->rules(['boolean'])->label('Published')->example('yes'),
            ImportColumn::make('rating')->integer()->rules(['nullable', 'integer', 'min:0']),
        ];
    }

    public function resolveRecord(): ?Model
    {
        return $this->newQuery()->firstOrNew(['slug' => $this->data['slug']]);
    }

    protected function beforeSave(): void
    {
        static::$hooks[] = 'beforeSave:'.$this->data['slug'];
    }

    protected function afterCreate(): void
    {
        static::$hooks[] = 'afterCreate:'.$this->data['slug'];
    }
}
