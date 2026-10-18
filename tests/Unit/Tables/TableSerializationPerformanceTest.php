<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\NumberFormat;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\ToggleColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

/**
 * Regression tests for the per-row cost of table serialisation (the 500-row benchmark
 * scenario): closures that do not depend on the row run once per pass, closures that do
 * run once per row, and the work grows linearly with the rows.
 */
function seedManyPosts(int $count): void
{
    $author = User::query()->create(['name' => 'Ada', 'email' => 'ada-perf@example.com', 'password' => 'x']);
    $category = Category::query()->create(['name' => 'News', 'slug' => 'news-perf']);
    $now = now()->toDateTimeString();

    foreach (array_chunk(range(1, $count), 250) as $chunk) {
        Post::query()->insert(array_map(static fn (int $i): array => [
            'title' => "Post {$i}",
            'slug' => "post-{$i}",
            'author_id' => $author->id,
            'category_id' => $category->id,
            'is_published' => $i % 2 === 0,
            'rating' => $i % 5,
            'status' => $i % 3 === 0 ? 'published' : 'draft',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }
}

/**
 * @param  array<string, int>  $calls
 */
function countingTable(array &$calls): Table
{
    $count = static function (string $name) use (&$calls): void {
        $calls[$name] = ($calls[$name] ?? 0) + 1;
    };

    return Table::make()
        ->query(Post::query())
        ->columns([
            TextColumn::make('title')->sortable(),
            TextColumn::make('author.name'),
            TextColumn::make('category.name'),
            TextColumn::make('status')->badge()->color(function (string $state) use ($count): string {
                $count('color');

                return $state === 'published' ? 'success' : 'gray';
            }),
            ToggleColumn::make('is_published'),
            TextColumn::make('rating')->numeric(decimalPlaces: 1),
            TextColumn::make('price')->state(function (Post $record) use ($count): int {
                $count('state');

                return $record->id * 100;
            })->money('USD', divideBy: 100),
            TextColumn::make('published_at')->dateTime(),
            TextColumn::make('created_at')->since(),
        ])
        ->recordActions(function () use ($count): array {
            $count('recordActions');

            return [
                Action::make('edit')
                    ->url(function (Post $record) use ($count): string {
                        $count('url');

                        return "/posts/{$record->id}/edit";
                    })
                    ->authorize(function (Post $record) use ($count): bool {
                        $count('authorize');

                        return $record->id % 7 !== 0;
                    }),
                Action::make('archive')->action(fn () => null),
            ];
        })
        ->recordUrl(fn (Post $record): string => "/posts/{$record->id}")
        ->paginationPageOptions([10, 50, 500])
        ->state(TableState::fromRequest(Request::create('/', 'GET', ['perPage' => 500])));
}

it('runs row-independent closures once per pass and row closures once per row', function (): void {
    seedManyPosts(500);
    $calls = [];

    $schema = countingTable($calls)->toSchema();

    expect($schema['records'])->toHaveCount(500)
        // The record actions are resolved once for all rows, not once per row.
        ->and($calls['recordActions'])->toBe(1)
        // Each row asks its record once, however many times the action checks it
        // (visibility, disabled state, serialisation).
        ->and($calls['authorize'])->toBe(500)
        // Authorised rows build their URL once (`isLink()` and `url` share it).
        ->and($calls['url'])->toBe(500 - intdiv(500, 7))
        // A cell resolves its state once for the formatter, its props and its closures.
        ->and($calls['state'])->toBe(500)
        ->and($calls['color'])->toBe(500);

    $row = collect($schema['records'])->firstWhere('key', 7);

    expect($row['cells']['price']['state'])->toBe('$7.00')
        ->and($row['cells']['rating']['state'])->toBe(Number::format(2, 1))
        ->and($row['cells']['status']['color'])->toBe('gray')
        ->and(collect($row['actions'])->pluck('name')->all())->toBe(['archive']);
});

it('serialises rows in linear time (500 rows cost about ten times 50)', function (): void {
    seedManyPosts(500);

    $measure = static function (int $perPage): float {
        $best = INF;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $calls = [];
            $table = countingTable($calls)
                ->state(TableState::fromRequest(Request::create('/', 'GET', ['perPage' => $perPage])));
            $table->getRecords();

            $started = hrtime(true);
            $table->toSchema();
            $best = min($best, (hrtime(true) - $started) / 1e6);
        }

        return $best;
    };

    $measure(50); // warm up autoloading, reflection and ICU
    $fifty = $measure(50);
    $fiveHundred = $measure(500);

    // Per-row work only: ten times the rows may cost at most ~20 times (generous for noisy
    // machines; a per-row repetition of per-table work shows up as far more).
    expect($fiveHundred / max($fifty, 0.001))->toBeLessThan(20.0);

    $before = memory_get_usage();
    $calls = [];
    $schema = countingTable($calls)->toSchema();

    // A row is about a kilobyte of JSON; the serialised rows stay within a few MB.
    expect(memory_get_usage() - $before)->toBeLessThan(24 * 1024 * 1024)
        ->and(strlen((string) json_encode($schema['records'])) / 500)->toBeLessThan(2048);
});

it('formats numbers and money exactly like Laravel with cached formatters', function (): void {
    foreach (['en', 'de', 'fr', 'ar', 'ja', 'pt_BR'] as $locale) {
        foreach ([0, -0.0, 1234.5, -1234.567, 0.004, -0.004, 1e9] as $number) {
            expect(NumberFormat::format($number, locale: $locale))->toBe(Number::format($number, locale: $locale))
                ->and(NumberFormat::format($number, 2, locale: $locale))->toBe(Number::format($number, 2, locale: $locale))
                ->and(NumberFormat::format($number, maxPrecision: 1, locale: $locale))->toBe(Number::format($number, maxPrecision: 1, locale: $locale))
                ->and(NumberFormat::currency($number, 'EUR', $locale))->toBe(Number::currency($number, 'EUR', $locale))
                ->and(NumberFormat::currency($number, 'JPY', $locale, 0))->toBe(Number::currency($number, 'JPY', $locale, 0));
        }
    }

    Number::useLocale('de');

    try {
        expect(NumberFormat::format(1234.5))->toBe(Number::format(1234.5));
    } finally {
        Number::useLocale('en');
    }
});

it('remembers policy lookups but sees policies registered later', function (): void {
    expect(Authorization::policyFor(Post::class))->toBeNull();

    $policy = new class
    {
        public function update(mixed $user, Post $post): bool
        {
            return false;
        }
    };

    Gate::policy(Post::class, $policy::class);

    expect(Authorization::policyFor(Post::class))->toBeInstanceOf($policy::class)
        ->and(Authorization::requiresRecord($policy, 'update'))->toBeTrue();
});
