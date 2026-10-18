<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;

it('serialises every Cropper.js view mode, including from closures', function (int $mode): void {
    $editor = FileUpload::make('cover')
        ->image()
        ->imageEditor()
        ->imageEditorMode(fn (): int => $mode)
        ->getImageEditor();

    expect($editor)->toMatchArray(['mode' => $mode]);
})->with([0, 1, 2, 3]);

it('leaves the default mode to the editor', function (): void {
    expect(FileUpload::make('cover')->imageEditor()->getImageEditor())->not->toHaveKey('mode');
});

it('sends no editor options when the editor is off', function (): void {
    expect(FileUpload::make('cover')->imageEditor(fn (): bool => false)->imageEditorMode(2)->getImageEditor())
        ->toBeNull();
});
