<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor\MentionProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks\DividerBlock;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks\HeroBlock;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    FormsBPage::reset();
    $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
});

afterEach(fn () => FormsBPage::reset());

function editor(): RichEditor
{
    return RichEditor::make('content')
        ->customBlocks([HeroBlock::class, DividerBlock::class])
        ->mentions([
            MentionProvider::make('@')->getSearchResultsUsing(fn (string $search): array => User::query()->where('name', 'like', "%{$search}%")->pluck('name', 'id')->all()),
            MentionProvider::make('#')->items(['php' => 'PHP', 'js' => 'JavaScript']),
        ])
        ->mergeTags(['name', 'today' => 'Today\'s date']);
}

function editorOptions(array $query): Illuminate\Testing\TestResponse
{
    return test()->getJson('/admin/_alfakit/schema/options?'.http_build_query([
        'token' => FormsBPage::token(test()->user),
        'component' => 'form',
        'path' => 'content',
        ...$query,
    ]));
}

it('serialises custom blocks, mentions and merge tags', function (): void {
    $props = schema([editor()->json()])->fill()->toSchema()[0];

    expect($props['json'])->toBeTrue()
        ->and(collect($props['customBlocks'])->pluck('id')->all())->toBe(['hero', 'divider'])
        ->and($props['customBlocks'][0]['label'])->toBe('Hero')
        ->and($props['customBlocks'][0]['icon'])->toBe('image')
        ->and($props['customBlocks'][0]['action']['name'])->toBe('customBlock:hero')
        ->and($props['customBlocks'][0]['action']['hasModal'])->toBeTrue()
        // Without a form, the block is inserted directly.
        ->and($props['customBlocks'][1]['action'])->not->toHaveKey('hasModal')
        ->and($props['mentions'])->toBe([
            ['trigger' => '@', 'searchable' => true, 'searchDebounce' => 250],
            ['trigger' => '#', 'items' => [['id' => 'php', 'label' => 'PHP'], ['id' => 'js', 'label' => 'JavaScript']]],
        ])
        ->and($props['mergeTags'])->toBe([['id' => 'name', 'label' => 'Name'], ['id' => 'today', 'label' => 'Today\'s date']])
        ->and(array_slice($props['toolbarButtons'], -3))->toBe(['|', 'customBlocks', 'mergeTags']);
});

it('searches mentions and renders block previews through the options endpoint', function (): void {
    User::query()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'x']);
    FormsBPage::$formUsing = fn (): array => [editor()];

    editorOptions(['mention' => '@', 'search' => 'jan'])
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Jane Doe');

    editorOptions(['mention' => '#', 'search' => 'java'])->assertOk()->assertJsonPath('options', [['value' => 'js', 'label' => 'JavaScript']]);
    editorOptions(['mention' => '!', 'search' => 'x'])->assertNotFound();

    editorOptions(['block' => 'hero', 'config' => json_encode(['heading' => '<b>Hi</b>'])])
        ->assertOk()
        ->assertJsonPath('preview', '<h2>&lt;b&gt;Hi&lt;/b&gt;</h2>');

    editorOptions(['block' => 'unknown', 'config' => '{}'])->assertNotFound();
});

it('refuses rich editor lookups for hidden or disabled editors', function (): void {
    FormsBPage::$formUsing = fn (): array => [editor()->hidden()];
    editorOptions(['mention' => '#', 'search' => ''])->assertNotFound();

    FormsBPage::$formUsing = fn (): array => [editor()->disabled()];
    editorOptions(['mention' => '#', 'search' => ''])->assertForbidden();
    // Disabled editors still show their blocks.
    editorOptions(['block' => 'hero', 'config' => '{}'])->assertOk();
});

it('runs the custom block modal as a field action', function (): void {
    FormsBPage::$formUsing = fn (): array => [editor()];

    $this->postJson('/admin/_alfakit/actions/modal', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:form::content',
        'action' => 'customBlock:hero',
        'arguments' => ['config' => ['heading' => 'Old'], 'editing' => true],
        'state' => ['content' => '<p>x</p>'],
    ])->assertOk()
        ->assertJsonPath('heading', 'Edit Hero')
        ->assertJsonPath('form.state.heading', 'Old');

    $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:form::content',
        'action' => 'customBlock:hero',
        'state' => ['content' => '<p>x</p>'],
        'data' => ['heading' => ''],
    ])->assertStatus(422)->assertJsonValidationErrors(['heading']);

    $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:form::content',
        'action' => 'customBlock:hero',
        'state' => ['content' => '<p>x</p>'],
        'data' => ['heading' => 'Welcome'],
    ])->assertOk()->assertJsonPath('result', ['id' => 'hero', 'config' => ['heading' => 'Welcome'], 'preview' => '<h2>Welcome</h2>', 'prose' => false]);
});

it('stores JSON documents and validates them as arrays', function (): void {
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]];

    $schema = schema([editor()->json()])->fill();
    $schema->applyClientState(['content' => $document]);

    expect($schema->validate())->toBe(['content' => $document]);

    $schema->applyClientState(['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
    expect($schema->validate())->toBe(['content' => null]);

    $schema->applyClientState(['content' => '<p>html</p>']);
    expect(fn () => $schema->validate())->toThrow(Illuminate\Validation\ValidationException::class);

    // HTML content with only a custom block is not empty.
    $html = schema([editor()])->fill();
    $html->applyClientState(['content' => '<div data-type="customBlock" data-id="divider" data-config="{}"></div>']);
    expect($html->validate()['content'])->toContain('customBlock');
});

it('renders HTML content with merge tags and custom blocks', function (): void {
    $html = '<p>Hi <span data-type="mergeTag" data-id="name">{{ name }}</span>, <span data-type="mergeTag" data-id="missing">{{ missing }}</span></p>'
        .'<div data-type="customBlock" data-id="hero" data-config="{&quot;heading&quot;:&quot;Big &lt;news&gt;&quot;}"></div>'
        .'<div data-type="customBlock" data-id="unknown" data-config="{}"></div>';

    expect(RichContentRenderer::make($html)->mergeTags(['name' => '<Jane>'])->customBlocks([HeroBlock::class => ['suffix' => '!']])->toHtml())
        ->toBe('<p>Hi &lt;Jane&gt;, {{ missing }}</p><section class="hero"><h2>Big &lt;news&gt;</h2>!</section>');
});

it('renders JSON content safely', function (): void {
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title <x>']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
            ['type' => 'text', 'text' => ' evil', 'marks' => [['type' => 'link', 'attrs' => ['href' => "java\tscript:alert(1)"]]]],
            ['type' => 'mention', 'attrs' => ['id' => '5', 'label' => 'Jane', 'char' => '@']],
            ['type' => 'mergeTag', 'attrs' => ['id' => 'name']],
        ]],
        ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']],
        ['type' => 'customBlock', 'attrs' => ['id' => 'hero', 'config' => ['heading' => 'Hey']]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]]]],
    ]];

    expect(RichContentRenderer::make($document)->mergeTags(['name' => new HtmlString('<em>J</em>')])->customBlocks([HeroBlock::class])->toHtml())
        ->toBe('<h2>Title &lt;x&gt;</h2><p><strong><a href="https://example.com">bold</a></strong> evil<span data-type="mention" data-id="5">@Jane</span><em>J</em></p><section class="hero"><h2>Hey</h2></section><ul><li><p>one</p></li></ul>');
});
