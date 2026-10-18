<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Imports;

use AlfaSix\Alfakit\Actions\Imports\Exceptions\RowImportFailedException;
use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;

class ParityPostImporter extends Importer
{
    use TracksImportHooks;

    protected static ?string $model = Post::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')->requiredMapping()->rules(['required', 'max:20']),
            ImportColumn::make('slug')->requiredMapping()->rules(['required']),
            ImportColumn::make('rating')->integer()->rules(['nullable', 'integer', 'min:0']),
            ImportColumn::make('tags_list')->multiple('|')->nestedRecursiveRules(['integer', 'min:1'])->rules(['array', 'max:3']),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [];
    }

    public function resolveRecord(): ?Model
    {
        if (($this->options['updateOnly'] ?? false) === true) {
            return $this->newQuery()->where('slug', $this->data['slug'])->first();
        }

        if ($this->data['slug'] === 'forbidden') {
            throw new RowImportFailedException('This slug is reserved.');
        }

        return $this->newQuery()->firstOrNew(['slug' => $this->data['slug']]);
    }

    protected function beforeSave(): void
    {
        $this->record?->setAttribute('author_id', $this->record->getAttribute('author_id') ?? auth()->id());
    }
}
