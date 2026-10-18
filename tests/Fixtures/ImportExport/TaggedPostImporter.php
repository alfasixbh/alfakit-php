<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;

/** Update-only importer with a BelongsToMany column. */
class TaggedPostImporter extends Importer
{
    protected static ?string $model = Post::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('slug')->requiredMapping(),
            ImportColumn::make('title'),
            ImportColumn::make('tags')->relationship(resolveUsing: 'name')->multiple('|'),
        ];
    }

    public function resolveRecord(): ?Model
    {
        if (($this->options['createMissing'] ?? false) === true) {
            return $this->newQuery()->firstOrNew(['slug' => $this->data['slug']]);
        }

        return $this->newQuery()->where('slug', $this->data['slug'])->first();
    }
}
