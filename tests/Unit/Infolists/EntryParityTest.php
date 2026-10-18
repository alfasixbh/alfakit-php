<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\CodeEditor\Enums\Language;
use AlfaSix\Alfakit\Infolists\Components\CodeEntry;
use AlfaSix\Alfakit\Infolists\Components\ImageEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use Illuminate\Database\Eloquent\Builder;

function entryParityPost(): Post
{
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello-'.uniqid(), 'rating' => 3]);
    $post->comments()->createMany([
        ['author_name' => 'Ann', 'body' => 'One', 'sort' => 4],
        ['author_name' => 'Bob', 'body' => 'Two', 'sort' => 8],
        ['author_name' => 'Ann', 'body' => 'Three', 'sort' => 0],
    ]);
    $post->tags()->attach([
        Tag::query()->create(['name' => 'Laravel'])->id,
        Tag::query()->create(['name' => 'PHP'])->id,
    ]);

    return $post;
}

/**
 * @return array<string, mixed>
 */
function serialiseEntry(mixed $entry, ?Post $record = null): array
{
    return schema([$entry], $record)->toSchema()[0];
}

// ---------------------------------------------------------------- Aggregates

it('loads relationship aggregates onto the record for text entries', function (): void {
    $post = entryParityPost()->fresh();

    $entries = schema([
        TextEntry::make('comments_count')->counts('comments'),
        TextEntry::make('comments_exists')->exists('comments'),
        TextEntry::make('comments_sum_sort')->sum('comments', 'sort'),
        TextEntry::make('comments_avg_sort')->avg('comments', 'sort'),
        TextEntry::make('comments_min_sort')->min('comments', 'sort'),
        TextEntry::make('comments_max_sort')->max('comments', 'sort'),
        TextEntry::make('ann_comments_count')->counts(['comments as ann_comments_count' => fn (Builder $query) => $query->where('author_name', 'Ann')]),
    ], $post)->toSchema();

    $states = collect($entries)->pluck('state', 'name');

    // Text entries send the formatted (string) value.
    expect((int) $states['comments_count'])->toBe(3)
        ->and((bool) $states['comments_exists'])->toBeTrue()
        ->and((int) $states['comments_sum_sort'])->toBe(12)
        ->and((float) $states['comments_avg_sort'])->toBe(4.0)
        ->and((int) $states['comments_min_sort'])->toBe(0)
        ->and((int) $states['comments_max_sort'])->toBe(8)
        ->and((int) $states['ann_comments_count'])->toBe(2);
});

it('loads raw expression aggregates for text entries', function (): void {
    $post = entryParityPost()->fresh();

    $states = collect(schema([
        TextEntry::make('double_sort')->sum('comments as double_sort', Illuminate\Support\Facades\DB::raw('sort * 2')),
        TextEntry::make('top_sort')->max('comments as top_sort', Illuminate\Support\Facades\DB::raw('sort + 1')),
    ], $post)->toSchema())->pluck('state', 'name');

    expect((int) $states['double_sort'])->toBe(24)
        ->and((int) $states['top_sort'])->toBe(9);
});

it('does not reload an aggregate the query already selected', function (): void {
    entryParityPost();
    $post = Post::query()->withCount('comments')->first();
    $post->setAttribute('comments_count', 42);

    expect(serialiseEntry(TextEntry::make('comments_count')->counts('comments'), $post)['state'])->toBe('42');
});

it('applies the related model global scopes to aggregates (tenancy)', function (): void {
    $post = entryParityPost()->fresh();

    Comment::addGlobalScope('tenant', fn (Builder $query) => $query->where('author_name', 'Bob'));

    try {
        expect(serialiseEntry(TextEntry::make('comments_count')->counts('comments'), $post)['state'])->toBe('1');
    } finally {
        Comment::clearBootedModels();
    }
});

// ---------------------------------------------------------------- $relatedRecord

it('evaluates colour, icon and url per related record of a relationship list', function (): void {
    $post = entryParityPost()->load('tags');

    $entry = serialiseEntry(
        TextEntry::make('tags.name')
            ->badge()
            ->color(fn (Tag $relatedRecord): string => $relatedRecord->name === 'PHP' ? 'info' : 'danger')
            ->icon(fn (string $state): string => $state === 'PHP' ? 'code' : 'flame')
            ->url(fn (Tag $relatedRecord, Post $record): string => "/posts/{$record->id}/tags/{$relatedRecord->id}"),
        $post,
    );
    $tags = $post->tags->keyBy('name');

    expect($entry['state'])->toBe(['Laravel', 'PHP'])
        ->and($entry['itemColors'])->toBe(['danger', 'info'])
        ->and($entry['itemIcons'])->toBe(['flame', 'code'])
        ->and($entry['itemUrls'])->toBe(["/posts/{$post->id}/tags/{$tags['Laravel']->id}", "/posts/{$post->id}/tags/{$tags['PHP']->id}"])
        ->and($entry)->not->toHaveKey('url')
        ->and($entry)->not->toHaveKey('color');
});

it('collapses per-item values that agree into one', function (): void {
    $post = entryParityPost();

    $entry = serialiseEntry(
        TextEntry::make('tags.name')->badge()->color(fn (?Tag $relatedRecord): Color => Color::Success),
        $post,
    );

    expect($entry['color'])->toBe('success')
        ->and($entry)->not->toHaveKey('itemColors');
});

it('keeps evaluating closures once for states that are not relationship lists', function (): void {
    $post = entryParityPost();
    $post->update(['tags_list' => ['a', 'b']]);

    $calls = 0;
    $entry = serialiseEntry(
        TextEntry::make('tags_list')->color(function (mixed $state, mixed $relatedRecord = null) use (&$calls): string {
            $calls++;

            return is_array($state) && $relatedRecord === null ? 'warning' : 'danger';
        }),
        $post->fresh(),
    );

    expect($entry['color'])->toBe('warning')
        ->and($calls)->toBeGreaterThanOrEqual(1)
        ->and($entry)->not->toHaveKey('itemColors');

    // `getStateUsing()` keeps the closure evaluated once with the whole state.
    $custom = serialiseEntry(
        TextEntry::make('tags.name')->state(['x', 'y'])->color(fn (mixed $state): string => is_array($state) ? 'info' : 'danger'),
        $post,
    );

    expect($custom['color'])->toBe('info');
});

it('sanitises per-item urls', function (): void {
    $post = entryParityPost();

    $entry = serialiseEntry(
        TextEntry::make('tags.name')->url(fn (Tag $relatedRecord): string => $relatedRecord->name === 'PHP' ? 'javascript:alert(1)' : '/ok'),
        $post,
    );

    expect($entry['itemUrls'])->toBe(['/ok', null]);
});

// ---------------------------------------------------------------- ImageEntry

it('accepts the Filament image size method names', function (): void {
    $entry = serialiseEntry(ImageEntry::make('avatar')->state('https://example.com/a.png')->imageWidth(80)->imageHeight('3rem')->imageSize(40));

    expect($entry['width'])->toBe(80)
        ->and($entry['height'])->toBe('3rem')
        ->and($entry['size'])->toBe(40);
});

it('serialises image alt text, evaluated per image', function (): void {
    $shared = serialiseEntry(ImageEntry::make('avatar')->state('https://example.com/a.png')->alt('Avatar'));

    expect($shared['alt'])->toBe('Avatar')->and($shared)->not->toHaveKey('alts');

    $perImage = serialiseEntry(
        ImageEntry::make('images')
            ->state(['https://example.com/front.png', 'https://example.com/back.png'])
            ->alt(fn (?string $state): string => str_contains((string) $state, 'front') ? 'Front' : 'Back'),
    );

    expect($perImage['alts'])->toBe(['Front', 'Back'])->and($perImage)->not->toHaveKey('alt');

    $post = entryParityPost();
    $fromRecord = serialiseEntry(ImageEntry::make('images')->state(['https://example.com/x.png'])->alt(fn (Post $record): string => "Cover of {$record->title}"), $post);

    expect($fromRecord['alt'])->toBe('Cover of Hello');

    $default = serialiseEntry(ImageEntry::make('missing')->defaultImageUrl('https://example.com/default.png')->alt(fn (?string $state): string => $state === null ? 'Placeholder' : 'Image'));

    expect($default['alt'])->toBe('Placeholder');
});

// ---------------------------------------------------------------- CodeEntry

it('serialises code entries with grammar, themes and copy options', function (): void {
    $entry = serialiseEntry(
        CodeEntry::make('code')
            ->state('<?php echo 1;')
            ->grammar(Language::Php)
            ->lightTheme('github-light')
            ->darkTheme(fn (): string => 'dracula')
            ->copyable()
            ->copyMessage('Copied!')
            ->copyMessageDuration(1500),
    );

    expect($entry)->toMatchArray([
        'type' => 'CodeEntry',
        'state' => '<?php echo 1;',
        'grammar' => 'php',
        'lightTheme' => 'github-light',
        'darkTheme' => 'dracula',
        'copyable' => true,
        'copyMessage' => 'Copied!',
        'copyMessageDuration' => 1500,
    ]);

    expect(serialiseEntry(CodeEntry::make('code')->state('x')->grammar('JS'))['grammar'])->toBe('js');
});

it('prints array state as JSON and defaults the grammar to json', function (): void {
    $post = entryParityPost();
    $post->update(['meta' => ['color' => 'red', 'url' => 'https://example.com/ü']]);

    $entry = serialiseEntry(CodeEntry::make('meta'), $post->fresh());

    expect($entry['grammar'])->toBe('json')
        ->and($entry['state'])->toBe("{\n    \"color\": \"red\",\n    \"url\": \"https://example.com/ü\"\n}");

    $compact = serialiseEntry(CodeEntry::make('meta')->jsonFlags(0)->grammar('yaml'), $post->fresh());

    expect($compact['state'])->toBe(json_encode(['color' => 'red', 'url' => 'https://example.com/ü']))
        ->and($compact['grammar'])->toBe('yaml');

    expect(serialiseEntry(CodeEntry::make('missing'), $post))->not->toHaveKey('state');
});

it('maps the Filament Actions component and CodeEntry in the migrator', function (): void {
    $classes = AlfaSix\Alfakit\Migration\FilamentClassMap::classes();
    $unsupported = AlfaSix\Alfakit\Migration\FilamentClassMap::unsupported();

    expect($classes['Filament\\Schemas\\Components\\Actions'])->toBe(AlfaSix\Alfakit\Schemas\Components\Actions::class)
        ->and($classes['Filament\\Forms\\Components\\Actions'])->toBe(AlfaSix\Alfakit\Schemas\Components\Actions::class)
        ->and($classes['Filament\\Infolists\\Components\\Actions'])->toBe(AlfaSix\Alfakit\Schemas\Components\Actions::class)
        ->and($classes['Filament\\Infolists\\Components\\CodeEntry'])->toBe(CodeEntry::class)
        ->and($unsupported)->not->toHaveKey('Filament\\Infolists\\Components\\CodeEntry')
        ->and($unsupported)->not->toHaveKey('Filament\\Schemas\\Components\\Actions');
});

it('evaluates colour, icon and url per related record in table columns too', function (): void {
    $post = entryParityPost()->load('tags');
    $tags = $post->tags->keyBy('name');

    $cell = AlfaSix\Alfakit\Tables\Columns\TextColumn::make('tags.name')
        ->badge()
        ->color(fn (Tag $relatedRecord): string => $relatedRecord->name === 'PHP' ? 'info' : 'danger')
        ->icon(fn (string $state): string => $state === 'PHP' ? 'code' : 'flame')
        ->url(fn (Tag $relatedRecord): string => "/tags/{$relatedRecord->id}")
        ->toCell($post);

    expect($cell['itemColors'])->toBe(['danger', 'info'])
        ->and($cell['itemIcons'])->toBe(['flame', 'code'])
        ->and($cell['itemUrls'])->toBe(["/tags/{$tags['Laravel']->id}", "/tags/{$tags['PHP']->id}"])
        ->and($cell)->not->toHaveKey('url')
        ->and($cell)->not->toHaveKey('color');
});

it('injects $relatedRecord into formatStateUsing(), limit() and prefix() per item', function (): void {
    $post = entryParityPost()->load('tags');

    $entry = serialiseEntry(
        TextEntry::make('tags.name')
            ->badge()
            ->formatStateUsing(fn (string $state, Tag $relatedRecord): string => "{$state}#{$relatedRecord->id}")
            ->limit(fn (?Tag $relatedRecord): int => $relatedRecord?->name === 'PHP' ? 2 : 50, end: '~')
            ->prefix(fn (?Tag $relatedRecord): string => $relatedRecord?->name === 'Laravel' ? 'L:' : ''),
        $post,
    );
    $tags = $post->tags->keyBy('name');

    expect($entry['state'])->toBe(["L:Laravel#{$tags['Laravel']->id}", 'PH~']);
});

it('passes the related record to icon column closures of a relationship list', function (): void {
    $post = entryParityPost()->load('tags');
    $column = AlfaSix\Alfakit\Tables\Columns\IconColumn::make('tags.name')
        ->icon(fn (Tag $relatedRecord): string => $relatedRecord->name === 'PHP' ? 'code' : 'flame')
        ->color(fn (?Tag $relatedRecord): ?string => $relatedRecord?->name === 'PHP' ? 'info' : null);

    $php = $post->tags->firstWhere('name', 'PHP');

    expect($column->getIconForState('PHP', $php))->toBe(['icon' => 'code', 'color' => 'info']);
});
