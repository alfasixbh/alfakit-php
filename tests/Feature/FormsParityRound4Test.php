<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\ColorPicker;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Repeater\TableColumn;
use AlfaSix\Alfakit\Forms\Components\RichEditor\MentionProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Slider;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\TimePicker;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Schemas\DeferredLoading;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A post whose `author_id` doubles as a "parent post" key, for self-referencing selects.
 */
function round4ParentPost(): Post
{
    return new class extends Post
    {
        protected $table = 'posts';

        public function parent(): BelongsTo
        {
            return $this->belongsTo(Post::class, 'author_id');
        }
    };
}

/**
 * @return array<string, mixed>
 */
function round4Props(mixed $component, ?Model $record = null): array
{
    return schema([$component], $record)->fill()->toSchema()[0];
}

// ------------------------------------------------------------------ TextInput

it('accepts Filament autocapitalize values as well as booleans', function (): void {
    expect(round4Props(TextInput::make('name')->autocapitalize('words'))['autocapitalize'])->toBe('words')
        ->and(round4Props(TextInput::make('name')->autocapitalize())['autocapitalize'])->toBe('on')
        ->and(round4Props(TextInput::make('name')->autocapitalize(true))['autocapitalize'])->toBe('on')
        ->and(round4Props(TextInput::make('name')->autocapitalize(false))['autocapitalize'])->toBe('off')
        ->and(round4Props(TextInput::make('name')->autocapitalize(fn (): string => 'characters'))['autocapitalize'])->toBe('characters')
        ->and(round4Props(TextInput::make('name')->autocapitalize(null)))->not->toHaveKey('autocapitalize')
        ->and(round4Props(TextInput::make('name')))->not->toHaveKey('autocapitalize');
});

// ------------------------------------------------------------------ Select

it('serialises reorderable() only for multiple selects', function (): void {
    $options = ['a' => 'A', 'b' => 'B'];

    expect(round4Props(Select::make('tags')->multiple()->reorderable()->options($options))['reorderable'])->toBeTrue()
        ->and(round4Props(Select::make('tag')->reorderable()->options($options)))->not->toHaveKey('reorderable')
        ->and(round4Props(Select::make('tags')->multiple()->reorderable(fn (): bool => false)->options($options)))->not->toHaveKey('reorderable');
});

it('keeps the chosen order of a reorderable multi-select', function (): void {
    $schema = schema([Select::make('tags_list')->multiple()->reorderable()->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])]);

    expect($schema->fill()->rawState(['tags_list' => ['c', 'a', 'b']])->validate())->toBe(['tags_list' => ['c', 'a', 'b']]);
});

it('leaves the edited record out of a self-referencing relationship select', function (): void {
    $first = Post::query()->create(['title' => 'First', 'slug' => 'first']);
    $second = Post::query()->create(['title' => 'Second', 'slug' => 'second']);
    $record = round4ParentPost()->newQuery()->findOrFail($first->getKey());

    $ignoring = Select::make('author_id')->relationship('parent', 'title', ignoreRecord: true);
    $keeping = Select::make('author_id')->relationship('parent', 'title');

    expect(round4Props($ignoring, $record)['options'])->toBe([['value' => (string) $second->getKey(), 'label' => 'Second']])
        ->and(collect(round4Props($keeping, $record)['options'])->pluck('label')->all())->toBe(['First', 'Second']);

    // The record itself is rejected on submit, other posts pass.
    $schema = schema([Select::make('author_id')->relationship('parent', 'title', ignoreRecord: true)], $record, operation: 'edit');

    expect(fn () => $schema->fill()->rawState(['author_id' => $first->getKey()])->validate())->toThrow(ValidationException::class)
        ->and($schema->fill()->rawState(['author_id' => $second->getKey()])->validate())->toBe(['author_id' => $second->getKey()]);
});

it('evaluates ignoreRecord closures and ignores it while creating', function (): void {
    $first = Post::query()->create(['title' => 'First', 'slug' => 'first']);
    Post::query()->create(['title' => 'Second', 'slug' => 'second']);
    $record = round4ParentPost()->newQuery()->findOrFail($first->getKey());

    $off = Select::make('author_id')->relationship('parent', 'title', ignoreRecord: fn (): bool => false);

    expect(round4Props($off, $record)['options'])->toHaveCount(2);

    $creating = schema([Select::make('author_id')->relationship('parent', 'title', ignoreRecord: true)], model: round4ParentPost()::class);

    expect($creating->fill()->toSchema()[0]['options'])->toHaveCount(2);
});

// ------------------------------------------------------------------ CheckboxList

it('labels checkbox list relationship options from each record', function (): void {
    Tag::query()->create(['name' => 'Laravel']);
    Tag::query()->create(['name' => 'PHP']);

    $props = round4Props(
        CheckboxList::make('tags')
            ->relationship('tags', 'name')
            ->getOptionLabelFromRecordUsing(fn (Tag $record): string => strtoupper($record->name))
            ->getOptionDescriptionFromRecordUsing(fn (Model $record): string => "#{$record->getKey()}"),
        new Post,
    );

    expect(collect($props['options'])->pluck('label')->all())->toBe(['LARAVEL', 'PHP'])
        ->and(collect($props['options'])->pluck('description')->all())->toBe(['#1', '#2']);
});

it('serialises checkbox list search texts and debounce', function (): void {
    $props = round4Props(
        CheckboxList::make('topics')
            ->options(['a' => 'A'])
            ->searchable()
            ->noSearchResultsMessage('Nothing here')
            ->searchPrompt(fn (): string => 'Find a topic')
            ->searchDebounce(400),
    );

    expect($props)->toMatchArray([
        'noSearchResultsMessage' => 'Nothing here',
        'searchPrompt' => 'Find a topic',
        'searchDebounce' => 400,
    ])->and(round4Props(CheckboxList::make('topics')->options(['a' => 'A'])))->not->toHaveKeys(['searchPrompt', 'searchDebounce', 'noSearchResultsMessage']);
});

// ------------------------------------------------------------------ Date pickers

it('serialises a per-field locale and uses it for the first day of the week', function (): void {
    $props = round4Props(DatePicker::make('starts_on')->locale('en_US'));
    $french = round4Props(DatePicker::make('starts_on')->locale(fn (): string => 'fr'));
    $forced = round4Props(DatePicker::make('starts_on')->locale('en_US')->weekStartsOnMonday());

    expect($props['locale'])->toBe('en-US')
        ->and($props['firstDayOfWeek'])->toBe(0)
        ->and($french['locale'])->toBe('fr')
        ->and($french['firstDayOfWeek'])->toBe(1)
        ->and($forced['firstDayOfWeek'])->toBe(1)
        ->and(round4Props(DatePicker::make('starts_on')))->not->toHaveKey('locale');
});

it('serialises date picker datalists, formatting date objects in the storage format', function (): void {
    $props = round4Props(DateTimePicker::make('starts_at')->datalist(['2026-01-01 09:00:00', Carbon::parse('2026-02-03 10:30:00')]));
    $time = round4Props(TimePicker::make('at')->datalist(fn (): array => ['09:00:00', '13:30:00']));

    expect($props['datalist'])->toBe(['2026-01-01 09:00:00', '2026-02-03 10:30'])
        ->and($time['datalist'])->toBe(['09:00:00', '13:30:00'])
        ->and(round4Props(DatePicker::make('d')->datalist([])))->not->toHaveKey('datalist');
});

// ------------------------------------------------------------------ FileUpload

it('serialises the image editor mode, fill colour and viewport', function (): void {
    $editor = round4Props(
        FileUpload::make('cover')
            ->image()
            ->imageEditor()
            ->imageEditorMode(0)
            ->imageEditorEmptyFillColor('#000000')
            ->imageEditorViewportWidth('1920')
            ->imageEditorViewportHeight(fn (): int => 1080),
    )['imageEditor'];

    expect($editor)->toBe([
        'aspectRatios' => [null],
        'circle' => false,
        'mode' => 0,
        'emptyFillColor' => '#000000',
        'viewportWidth' => 1920,
        'viewportHeight' => 1080,
    ]);
});

it('drops invalid image editor options', function (): void {
    $editor = round4Props(
        FileUpload::make('cover')
            ->imageEditor()
            ->imageEditorMode(7)
            ->imageEditorEmptyFillColor('red"><script>')
            ->imageEditorViewportWidth(1920),
    )['imageEditor'];

    expect($editor)->toBe(['aspectRatios' => [null], 'circle' => false]);
});

it('aliases Filament v4 automatic crop and resize names', function (): void {
    $props = round4Props(
        FileUpload::make('cover')
            ->image()
            ->imageAspectRatio('16:9')
            ->automaticallyCropImagesToAspectRatio()
            ->automaticallyResizeImagesMode('cover')
            ->automaticallyResizeImagesToWidth('1920')
            ->automaticallyResizeImagesToHeight(1080),
    );
    $explicit = round4Props(FileUpload::make('cover')->automaticallyCropImagesToAspectRatio('4:3'));

    expect($props)->toMatchArray([
        'imageCropAspectRatio' => '16:9',
        'imageResizeMode' => 'cover',
        'imageResizeTargetWidth' => 1920,
        'imageResizeTargetHeight' => 1080,
    ])->and($explicit['imageCropAspectRatio'])->toBe('4:3');
});

it('serialises the uploading message', function (): void {
    expect(round4Props(FileUpload::make('file')->uploadingMessage('Sending…'))['uploadingMessage'])->toBe('Sending…');
});

it('no longer removes the image editor options when migrating from Filament', function (): void {
    expect(FilamentClassMap::removedMethods())->not->toHaveKeys([
        'imageEditorMode',
        'imageEditorEmptyFillColor',
        'imageEditorViewportWidth',
        'imageEditorViewportHeight',
    ]);
});

// ------------------------------------------------------------------ Rich content

it('renders mentions as links when the provider has a url()', function (): void {
    $providers = [
        MentionProvider::make('@')->items(['5' => 'Jane'])->url(fn (string $id, string $label): string => "/users/{$id}?name={$label}"),
        MentionProvider::make('#')->items(['php' => 'PHP']),
    ];
    $html = '<p>Hi <span data-type="mention" data-id="5" data-label="Jane" data-char="@">@Jane</span> about <span data-type="mention" data-id="php">#PHP</span></p>';

    expect(RichContentRenderer::make($html)->mentions($providers)->toHtml())
        ->toBe('<p>Hi <a href="/users/5?name=Jane" data-type="mention" data-id="5">@Jane</a> about <span data-type="mention" data-id="php">#PHP</span></p>');
});

it('renders JSON mentions as links and drops unsafe mention URLs', function (): void {
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'mention', 'attrs' => ['id' => '5', 'label' => 'Jane', 'char' => '@']],
    ]]]];

    $linked = RichContentRenderer::make($doc)->mentions([MentionProvider::make('@')->url(fn (string $id): string => "https://example.com/u/{$id}")])->toHtml();
    $unsafe = RichContentRenderer::make($doc)->mentions([MentionProvider::make('@')->url(fn (): string => 'javascript:alert(1)')])->toHtml();
    $none = RichContentRenderer::make($doc)->mentions([MentionProvider::make('@')->url(fn (): ?string => null)])->toHtml();

    expect($linked)->toBe('<p><a href="https://example.com/u/5" data-type="mention" data-id="5">@Jane</a></p>')
        ->and($unsafe)->not->toContain('javascript')
        ->and($unsafe)->toContain('<span data-type="mention"')
        ->and($none)->toContain('<span data-type="mention"');
});

it('keeps grid, lead and small content through the strict sanitiser', function (): void {
    $html = '<div data-type="grid" class="alfakit-grid alfakit-grid-cols-2 fixed inset-0"><div data-type="gridColumn" class="alfakit-grid-column"><p class="alfakit-lead">Lead</p></div><div data-type="gridColumn" class="alfakit-grid-column"><p><small>Fine print</small></p></div></div>';

    expect(RichContentRenderer::make($html)->toHtml())
        ->toBe('<div data-type="grid" class="alfakit-grid alfakit-grid-cols-2"><div data-type="gridColumn" class="alfakit-grid-column"><p class="alfakit-lead">Lead</p></div><div data-type="gridColumn" class="alfakit-grid-column"><p><small>Fine print</small></p></div></div>');
});

it('renders JSON grids, lead paragraphs and small text', function (): void {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'grid', 'attrs' => ['columns' => 9], 'content' => [
            ['type' => 'gridColumn', 'content' => [['type' => 'paragraph', 'attrs' => ['lead' => true], 'content' => [['type' => 'text', 'text' => 'Lead']]]]],
            ['type' => 'gridColumn', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Small', 'marks' => [['type' => 'small']]]]]]],
        ]],
    ]];

    expect(RichContentRenderer::make($doc)->toHtml())
        ->toBe('<div data-type="grid" class="alfakit-grid alfakit-grid-cols-6"><div data-type="gridColumn" class="alfakit-grid-column"><p class="alfakit-lead">Lead</p></div><div data-type="gridColumn" class="alfakit-grid-column"><p><small>Small</small></p></div></div>');
});

// ------------------------------------------------------------------ Repeater & Builder

it('serialises compact() only for table repeaters', function (): void {
    $table = round4Props(Repeater::make('lines')->table([TableColumn::make('Name')])->compact()->schema([TextInput::make('name')]));
    $stacked = round4Props(Repeater::make('lines')->compact()->schema([TextInput::make('name')]));
    $off = round4Props(Repeater::make('lines')->table([TableColumn::make('Name')])->compact(fn (): bool => false)->schema([TextInput::make('name')]));

    expect($table['compact'])->toBeTrue()
        ->and($stacked)->not->toHaveKey('compact')
        ->and($off)->not->toHaveKey('compact');
});

it('serialises builder block search', function (): void {
    $blocks = [Block::make('heading')->schema([TextInput::make('text')])];

    expect(round4Props(Builder::make('content')->blocks($blocks)->searchable()->searchDebounce(500)))->toMatchArray(['blockSearch' => true, 'blockSearchDebounce' => 500])
        ->and(round4Props(Builder::make('content')->blocks($blocks)->searchable()->blockSearchDebounce(fn (): int => 250))['blockSearchDebounce'])->toBe(250)
        ->and(round4Props(Builder::make('content')->blocks($blocks)->searchDebounce(500)))->not->toHaveKeys(['blockSearch', 'blockSearchDebounce']);
});

// ------------------------------------------------------------------ Small fields

it('validates Textarea length()', function (): void {
    $schema = schema([Textarea::make('code')->length(4)]);

    expect(fn () => $schema->fill()->rawState(['code' => 'abc'])->validate())->toThrow(ValidationException::class)
        ->and(fn () => $schema->fill()->rawState(['code' => 'abcde'])->validate())->toThrow(ValidationException::class)
        ->and($schema->fill()->rawState(['code' => 'abcd'])->validate())->toBe(['code' => 'abcd'])
        ->and(round4Props(Textarea::make('code')->length(4)))->toMatchArray(['minLength' => 4, 'maxLength' => 4]);
});

it('serialises the rgba colour format the alpha control uses', function (): void {
    $schema = schema([ColorPicker::make('tint')->rgba()]);

    expect(round4Props(ColorPicker::make('tint')->rgba())['format'])->toBe('rgba')
        ->and($schema->fill()->rawState(['tint' => 'rgba(10, 20, 30, 0.45)'])->validate())->toBe(['tint' => 'rgba(10, 20, 30, 0.45)']);
});

it('serialises toggle button tooltips, size and full width', function (): void {
    $props = round4Props(
        ToggleButtons::make('status')
            ->options(['draft' => 'Draft', 'published' => 'Published'])
            ->tooltips(['draft' => 'Set as a draft', 'published' => ''])
            ->size(Size::Small)
            ->fullWidth(),
    );

    expect($props)->toMatchArray([
        'tooltips' => ['draft' => 'Set as a draft'],
        'size' => 'sm',
        'fullWidth' => true,
    ])->and(round4Props(ToggleButtons::make('s')->options(['a' => 'A'])->size('md')))->not->toHaveKey('size')
        ->and(round4Props(ToggleButtons::make('s')->options(['a' => 'A'])->size('my-button')->tooltips(fn (): array => ['a' => 'Tip']))['size'])->toBe('my-button')
        ->and(round4Props(ToggleButtons::make('s')->options(['a' => 'A'])))->not->toHaveKeys(['tooltips', 'size', 'fullWidth']);
});

it('serialises vertical sliders running top to bottom and per-handle flags', function (): void {
    $props = round4Props(
        Slider::make('range')
            ->range(0, 100)
            ->default([20, 80])
            ->vertical(isTopToBottom: true)
            ->tooltips([true, false])
            ->fillTrack([false, true, false]),
    );

    expect($props)->toMatchArray([
        'vertical' => true,
        'topToBottom' => true,
        'tooltips' => [true, false],
        'fillTrack' => [false, true, false],
    ])
        // Filament's spelling: `vertical()->rtl(false)`.
        ->and(round4Props(Slider::make('v')->vertical()->rtl(false))['topToBottom'])->toBeTrue()
        ->and(round4Props(Slider::make('v')->vertical()))->not->toHaveKey('topToBottom')
        ->and(round4Props(Slider::make('h')->rtl(false)))->not->toHaveKey('topToBottom')
        ->and(round4Props(Slider::make('b')->tooltips()->fillTrack()))->toMatchArray(['tooltips' => true, 'fillTrack' => true]);
});

it('sends deferLoading() repeaters and builders as placeholders on the first render only', function (): void {
    $repeater = fn () => Repeater::make('lines')->deferLoading()->schema([TextInput::make('name')]);
    $builder = fn () => Builder::make('content')->deferLoading(fn (): bool => true)->blocks([Block::make('heading')->schema([TextInput::make('text')])]);

    $placeholder = DeferredLoading::withPlaceholders(fn (): array => schema([$repeater()])->fill(['lines' => [['name' => 'A']]])->toSchema()[0]);
    $blocks = DeferredLoading::withPlaceholders(fn (): array => schema([$builder()])->fill(['content' => [['type' => 'heading', 'data' => ['text' => 'Hi']]]])->toSchema()[0]);
    $full = schema([$repeater()])->fill(['lines' => [['name' => 'A']]])->toSchema()[0];

    expect($placeholder)->toMatchArray(['deferred' => true, 'deferredPaths' => ['lines'], 'items' => [], 'template' => []])
        ->and($blocks)->toMatchArray(['deferred' => true, 'deferredPaths' => ['content'], 'items' => [], 'blocks' => []])
        ->and($full)->not->toHaveKey('deferred')
        ->and($full['items'])->toHaveCount(1);
});

it('keeps validating deferred repeaters', function (): void {
    $schema = schema([Repeater::make('lines')->deferLoading()->schema([TextInput::make('name')->required()])]);

    expect(fn () => DeferredLoading::withPlaceholders(fn () => $schema->fill()->rawState(['lines' => [['name' => null]]])->validate()))
        ->toThrow(ValidationException::class);
});
