<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPostResource;
use Closure;

class EditParityPost extends EditRecord
{
    protected static string $resource = ParityPostResource::class;

    public static ?Closure $contentUsing = null;

    public static ?string $defaultActionName = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->action(function (): void {
                $this->record->update(['status' => 'published', 'title' => 'Changed elsewhere']);
                $this->refreshFormData(['status']);
            }),
            Action::make('rename')
                ->schema([\AlfaSix\Alfakit\Forms\Components\TextInput::make('title')->required()])
                ->action(fn (array $data) => $this->record->update(['title' => $data['title']])),
            $this->getSaveFormAction()->formId('form'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return static::$contentUsing !== null ? (static::$contentUsing)($schema, $this) : $schema;
    }

    public function getDefaultAction(): ?string
    {
        return static::$defaultActionName;
    }
}
