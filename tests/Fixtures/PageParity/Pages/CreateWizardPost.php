<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord\Concerns\HasWizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityWizardResource;

class CreateWizardPost extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ParityWizardResource::class;

    public static int $startStep = 1;

    protected function getSteps(): array
    {
        return [
            Step::make('Basics')->schema([TextInput::make('title')->required()]),
            Step::make('Address')->schema([TextInput::make('slug')->required()]),
            Step::make('Review')->schema([TextInput::make('status')->default('draft')]),
        ];
    }

    public function getStartStep(): int
    {
        return static::$startStep;
    }

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'author_id' => auth()->id()];
    }
}
