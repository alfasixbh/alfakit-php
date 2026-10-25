<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpPostResource;

beforeEach(function (): void {
    TpPanelProvider::registerPanel();
});

afterEach(function (): void {
    TpPostResource::$table = null;
});

it('lists custom records() in a resource table without linking the rows', function (): void {
    // tables/records "Custom data": the rows are arrays, so there is no edit or view page to open.
    TpPostResource::$table = static fn (Table $table): Table => $table
        ->records(fn (): array => [['id' => 1, 'title' => 'From an API'], ['id' => 2, 'title' => 'Another']])
        ->columns([TextColumn::make('title')]);

    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']));

    $records = inertiaProps(inertiaGet($this, '/tp/posts')->assertOk())['page']['content'][0]['records'];

    expect($records)->toHaveCount(2)
        ->and($records[0]['url'] ?? null)->toBeNull();
});
