<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;

it('keeps the built-in type for subclasses of built-in columns', function (): void {
    $status = new class('status') extends IconColumn {};
    $money = new class('total') extends TextColumn {};

    expect($status->getType())->toBe('IconColumn')
        ->and($money->getType())->toBe('TextColumn')
        ->and(TextColumn::make('name')->getType())->toBe('TextColumn');
});

it('lets custom columns declare a registry type', function (): void {
    $progress = new class('progress') extends Column
    {
        protected ?string $type = 'progress';
    };
    $custom = new class('rating') extends IconColumn
    {
        protected ?string $type = 'rating';
    };

    expect($progress->getType())->toBe('progress')
        ->and($custom->getType())->toBe('rating');
});
