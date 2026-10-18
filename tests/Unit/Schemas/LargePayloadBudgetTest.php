<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;

/*
 * Regression budgets for large payloads. They are loose enough for a loaded CI runner
 * (several times the local measurements noted next to each) and tight enough to catch an
 * accidental O(n²) in serialisation or a schema prop that balloons the payload.
 */

/**
 * @return array{bytes: int, seconds: float, payload: array<string, mixed>}
 */
function measurePayload(Closure $build): array
{
    $started = hrtime(true);
    $payload = $build();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return [
        'bytes' => strlen($json),
        'seconds' => (hrtime(true) - $started) / 1e9,
        'payload' => $payload,
    ];
}

it('serialises a 500-item repeater within budget', function (): void {
    $form = schema([
        Repeater::make('items')->schema([
            TextInput::make('name')->required()->maxLength(120),
            Select::make('kind')->options(['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma']),
            FileUpload::make('attachment')->image(),
        ]),
    ]);
    $form->fill([
        'items' => array_map(fn (int $i): array => ['name' => "Item {$i}", 'kind' => 'a', 'attachment' => null], range(1, 500)),
    ]);

    $measured = measurePayload(fn (): array => $form->toPayload());
    $repeater = $measured['payload']['components'][0];

    // Measured locally (M-series Mac, SQLite, 3 runs): 0.03 s and 362 KB; items carry their own
    // resolved schema, so the size grows linearly with the item count.
    expect($repeater['items'])->toHaveCount(500)
        ->and($repeater['virtualize'])->toBe(['threshold' => 50])
        ->and($measured['seconds'])->toBeLessThan(0.5)
        ->and($measured['bytes'])->toBeLessThan(750_000);
});

it('serialises a 1,000-row, 12-column table within budget', function (): void {
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $rows = [];

    for ($i = 1; $i <= 1000; $i++) {
        $rows[] = [
            'title' => sprintf('Post %04d', $i),
            'slug' => "post-{$i}",
            'author_id' => $user->id,
            'rating' => $i % 6,
            'is_published' => $i % 2 === 0,
            'status' => ['draft', 'review', 'published'][$i % 3],
            'content' => str_repeat('Lorem ipsum ', 10),
            'published_at' => now()->subDays($i),
            'created_at' => now()->subDays($i),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 250) as $chunk) {
        Post::query()->insert($chunk);
    }

    $table = Table::make()
        ->query(Post::query()->with('author'))
        ->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('title')->searchable()->sortable()->copyable(),
            TextColumn::make('slug')->color('gray'),
            TextColumn::make('author.name')->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('rating')->numeric()->sortable(),
            IconColumn::make('is_published')->boolean(),
            TextColumn::make('category.name')->placeholder('—'),
            TextColumn::make('content')->limit(40),
            TextColumn::make('published_at')->dateTime(),
            TextColumn::make('created_at')->since(),
            TextColumn::make('updated_at')->date(),
        ])
        ->defaultSort('id')
        ->paginationPageOptions([1000])
        ->defaultPaginationPageOption(1000)
        ->state(TableState::fromRequest(Request::create('/', 'GET')));

    $measured = measurePayload(fn (): array => $table->toSchema());

    // Measured locally (M-series Mac, SQLite, 3 runs): 0.19 s and 494 KB for 1,000 records.
    expect($measured['payload']['records'])->toHaveCount(1000)
        ->and($measured['payload']['columns'])->toHaveCount(12)
        ->and($measured['payload']['virtualize'])->toBe(['threshold' => 50])
        ->and($measured['seconds'])->toBeLessThan(2.0)
        ->and($measured['bytes'])->toBeLessThan(1_000_000);
})->group('perf');
