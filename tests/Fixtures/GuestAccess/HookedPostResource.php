<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\GuestAccess;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/** Resource pages that record every lifecycle hook (page methods and trait methods). */
class HookedPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'hooked-posts';

    /** @var array<int, string> */
    public static array $log = [];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required(), TextInput::make('slug')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHookedPosts::route('/'),
            'create' => CreateHookedPost::route('/create'),
            'view' => ViewHookedPost::route('/{record}'),
            'edit' => EditHookedPost::route('/{record}/edit'),
        ];
    }
}

/** Trait hooks are discovered as `{hook}{TraitName}()`, like Filament. */
trait LogsHooks
{
    protected function afterFillLogsHooks(): void
    {
        HookedPostResource::$log[] = 'trait:afterFill';
    }

    protected function beforeValidateLogsHooks(): void
    {
        HookedPostResource::$log[] = 'trait:beforeValidate';
    }

    protected function beforeCreateLogsHooks(array $data): void
    {
        HookedPostResource::$log[] = 'trait:beforeCreate:'.$data['title'];
    }

    protected function afterSaveLogsHooks(): void
    {
        HookedPostResource::$log[] = 'trait:afterSave:'.$this->getRecord()?->getAttribute('title');
    }
}

class ListHookedPosts extends ListRecords
{
    protected static string $resource = HookedPostResource::class;
}

class CreateHookedPost extends CreateRecord
{
    use LogsHooks;

    protected static string $resource = HookedPostResource::class;

    protected function beforeFill(): void
    {
        HookedPostResource::$log[] = 'beforeFill';
    }

    protected function afterFill(): void
    {
        HookedPostResource::$log[] = 'afterFill';
    }

    protected function beforeValidate(): void
    {
        HookedPostResource::$log[] = 'beforeValidate';
    }

    protected function afterValidate(): void
    {
        HookedPostResource::$log[] = 'afterValidate';
    }

    protected function beforeCreate(array $data): void
    {
        HookedPostResource::$log[] = 'beforeCreate';

        if ($data['title'] === 'halt') {
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        HookedPostResource::$log[] = 'afterCreate';
    }
}

class EditHookedPost extends EditRecord
{
    use LogsHooks;

    protected static string $resource = HookedPostResource::class;

    protected function beforeFill(): void
    {
        HookedPostResource::$log[] = 'beforeFill:'.$this->getRecord()?->getAttribute('title');
    }

    protected function afterFill(): void
    {
        HookedPostResource::$log[] = 'afterFill:'.$this->getForm()?->getRawState()['title'];
    }

    // Filament-style hooks take no arguments.
    protected function beforeValidate(): void
    {
        HookedPostResource::$log[] = 'beforeValidate';
    }

    protected function afterValidate(): void
    {
        HookedPostResource::$log[] = 'afterValidate';
    }

    protected function beforeSave(array $data): void
    {
        HookedPostResource::$log[] = 'beforeSave';
    }

    protected function afterSave(): void
    {
        HookedPostResource::$log[] = 'afterSave';
    }
}

class ViewHookedPost extends ViewRecord
{
    use LogsHooks;

    protected static string $resource = HookedPostResource::class;

    protected function afterFill(): void
    {
        HookedPostResource::$log[] = 'view:afterFill';
    }
}
