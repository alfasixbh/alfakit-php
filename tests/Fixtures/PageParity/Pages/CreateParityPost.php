<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPostResource;
use Closure;
use Illuminate\Support\Arr;

class CreateParityPost extends CreateRecord
{
    protected static string $resource = ParityPostResource::class;

    /** @var array<int, string> */
    public static array $preserve = [];

    public static ?Closure $contentUsing = null;

    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, static::$preserve);
    }

    public function content(Schema $schema): Schema
    {
        return static::$contentUsing !== null ? (static::$contentUsing)($schema, $this) : $schema;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'author_id' => auth()->id()];
    }
}
