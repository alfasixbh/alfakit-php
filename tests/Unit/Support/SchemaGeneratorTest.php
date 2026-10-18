<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\SchemaGenerator;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

it('writes form fields for a model from its table columns', function (): void {
    ['code' => $code, 'imports' => $imports] = SchemaGenerator::formComponents(Post::class);

    expect($code)->toContain("                Select::make('author_id')\n                    ->relationship('author', 'name')")
        ->toContain("TextInput::make('title')\n                    ->maxLength(255)\n                    ->required(),")
        ->toContain("Textarea::make('content')")
        ->toContain("Toggle::make('is_published'),")
        ->toContain("DateTimePicker::make('published_at'),")
        ->toContain("TextInput::make('rating')\n                    ->integer(),")
        ->not->toContain("make('id')")
        ->not->toContain("make('created_at')")
        ->not->toContain("make('deleted_at')")
        // A column with a default is not required.
        ->and($code)->toContain("TextInput::make('status')\n                    ->maxLength(255),")
        ->and($imports)->toContain('AlfaSix\Alfakit\Forms\Components\Select')
        ->and($imports)->toContain('AlfaSix\Alfakit\Forms\Components\Toggle')
        ->and($imports)->toBe(array_values(array_unique($imports)));
});

it('writes table columns, with a hidden created_at column', function (): void {
    ['code' => $code, 'imports' => $imports] = SchemaGenerator::tableColumns(Post::class, indent: 4);

    expect($code)->toContain("    TextColumn::make('author.name')\n        ->sortable(),")
        ->toContain("IconColumn::make('is_published')\n        ->boolean(),")
        ->toContain("TextColumn::make('rating')\n        ->numeric()")
        ->toContain("TextColumn::make('created_at')")
        ->not->toContain("make('content')")
        ->not->toContain("make('meta')")
        ->and($imports)->toBe(['AlfaSix\Alfakit\Tables\Columns\IconColumn', 'AlfaSix\Alfakit\Tables\Columns\TextColumn']);
});

it('skips auth bookkeeping columns and rejects non-models', function (): void {
    expect(SchemaGenerator::formComponents(new User)['code'])->not->toContain('password')
        ->not->toContain('remember_token')
        ->and(fn () => SchemaGenerator::formComponents(stdClass::class))->toThrow(InvalidArgumentException::class);
});
