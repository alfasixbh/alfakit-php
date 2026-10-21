<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\MarkdownEditor\MarkdownFaker;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor\EditorCommand;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentFaker;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichEditorTool;
use AlfaSix\Alfakit\Forms\Components\RichEditor\ToolbarButtonGroup;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks\DividerBlock;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks\HeroBlock;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\RichContent\HighlightPlugin;
use AlfaSix\Alfakit\Tests\Fixtures\RichContent\RichPost;
use Faker\Factory;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    FormsBPage::reset();
    RichPost::$setUps = 0;
});

afterEach(fn () => FormsBPage::reset());

// ---------------------------------------------------------------- Toolbar layout

it('serialises grouped toolbar buttons and floating toolbars', function (): void {
    $props = schema([
        RichEditor::make('content')
            ->toolbarButtons([
                ToolbarButtonGroup::make('Format', ['h1', 'h2', 'strike'])->icon('heading')->textualButtons(),
                'bold', 'strike', '|',
                ToolbarButtonGroup::make('Hidden', ['strike']),
            ])
            ->disableToolbarButtons(['strike'])
            ->floatingToolbars([
                'paragraph' => ['bold', 'italic', ToolbarButtonGroup::make('Script', ['subscript', 'superscript'])],
                'table' => ['tableDelete'],
                'heading' => ['strike'],
            ]),
    ])->fill()->toSchema()[0];

    expect($props['toolbarButtons'])->toBe(['group:0', 'bold', '|'])
        ->and($props['toolbarButtonGroups']['group:0'])->toBe(['label' => 'Format', 'icon' => 'heading', 'buttons' => ['h1', 'h2'], 'textual' => true])
        ->and($props['floatingToolbars'])->toBe([
            'paragraph' => ['bold', 'italic', 'group:1'],
            'table' => ['tableDelete'],
        ])
        ->and($props['toolbarButtonGroups']['group:1'])->toBe(['label' => 'Script', 'buttons' => ['subscript', 'superscript']]);
});

it('sends the text colour palette when the button is only in a group or floating toolbar', function (): void {
    $props = schema([RichEditor::make('content')->toolbarButtons(['bold'])->floatingToolbars(['paragraph' => ['textColor']])])->fill()->toSchema()[0];

    expect($props['textColors'])->not->toBeEmpty();
});

it('serialises sticky, panel and image options with closures', function (): void {
    $props = schema([
        RichEditor::make('content')
            ->stickyToolbar(fn (): bool => true)
            ->stickyPanels()
            ->stickyOffset('5rem')
            ->resizableImages()
            ->minimalCustomBlockControls()
            ->customBlocksGrid()
            ->searchableCustomBlocks()
            ->activePanel(fn (): string => 'customBlocks')
            ->customBlocks(['Marketing' => [HeroBlock::class], DividerBlock::class]),
    ])->fill()->toSchema()[0];

    expect($props)->toMatchArray([
        'stickyToolbar' => true,
        'stickyPanels' => true,
        'stickyOffset' => '5rem',
        'resizableImages' => true,
        'minimalCustomBlockControls' => true,
        'customBlocksGrid' => true,
        'searchableCustomBlocks' => true,
        'activePanel' => 'customBlocks',
    ])
        // Ungrouped blocks first.
        ->and(collect($props['customBlocks'])->map(fn (array $block): array => [$block['id'], $block['group'] ?? null])->all())
        ->toBe([['divider', null], ['hero', 'Marketing']]);

    $defaults = schema([RichEditor::make('content')])->fill()->toSchema()[0];

    foreach (['stickyToolbar', 'stickyOffset', 'resizableImages', 'activePanel', 'toolbarButtonGroups', 'floatingToolbars', 'editorTools', 'extensions'] as $key) {
        expect($defaults)->not->toHaveKey($key);
    }
});

it('rejects sticky offsets and panels that are not plain values', function (): void {
    $props = schema([RichEditor::make('content')->stickyToolbar()->stickyOffset('1px; background: url(x)')->activePanel('nope')])->fill()->toSchema()[0];

    expect($props['stickyToolbar'])->toBeTrue()->and($props)->not->toHaveKey('stickyOffset')->not->toHaveKey('activePanel');
});

it('keeps merge tag labels for listed tags', function (): void {
    $props = schema([RichEditor::make('content')->mergeTags(['name', 'today'])->mergeTagLabels(['name' => 'Full name'])])->fill()->toSchema()[0];

    expect($props['mergeTags'])->toBe([['id' => 'name', 'label' => 'Full name'], ['id' => 'today', 'label' => 'Today']]);
});

// ---------------------------------------------------------------- Resizable images

it('keeps image widths as plain pixel sizes', function (): void {
    expect(Html::sanitize('<img src="/a.png" width="320" height="200">'))->toBe('<img src="/a.png" width="320" height="200" />')
        ->and(Html::sanitize('<img src="/a.png" width="320px">'))->toContain('width="320"')
        ->and(Html::sanitize('<img src="/a.png" width="50%">'))->toContain('width="50%"')
        ->and(Html::sanitize('<img src="/a.png" width="99999999" height="calc(100vh)">'))->not->toContain('width')->not->toContain('height')
        ->and(Html::sanitize('<img src="/a.png" width="1&quot; onload=&quot;x">'))->not->toContain('onload');

    $json = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/a.png', 'width' => 240, 'height' => 100]]]];

    expect(RichContentRenderer::make($json)->toHtml())->toBe('<img src="/a.png" width="240">')
        ->and(RichContentRenderer::make(['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/a.png', 'width' => '"><script>']]]])->toHtml())->toBe('<img src="/a.png">');
});

// ---------------------------------------------------------------- Plugins

it('serialises plugin tools, extensions and toolbar changes', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);

    $props = schema([RichEditor::make('content')->toolbarButtons(['bold', 'strike', 'highlightColor'])->plugins([HighlightPlugin::make()])])->fill()->toSchema()[0];

    $tools = collect($props['editorTools'])->keyBy('name');

    expect($props['extensions'])->toBe(['highlight-color'])
        // Plugin buttons are enabled first, then the plugin's disabled ones removed.
        ->and($props['toolbarButtons'])->toBe(['bold', 'highlightColor', '|', 'shout'])
        ->and($tools['shout'])->toBe(['name' => 'shout', 'label' => 'Shout', 'icon' => 'megaphone', 'commands' => [['toggleMark', 'shout']], 'active' => ['name' => 'shout']])
        ->and($tools['highlightColor']['action']['name'])->toBe('highlightColor')
        ->and($tools['highlightColor']['action']['hasModal'])->toBeTrue();

    // Disabled editors don't offer plugin actions.
    $disabled = schema([RichEditor::make('content')->plugins([HighlightPlugin::make()])->disabled()])->fill()->toSchema()[0];

    expect(collect($disabled['editorTools'])->keyBy('name')['highlightColor'])->not->toHaveKey('action');
});

it('runs plugin editor actions and returns editor commands', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    FormsBPage::$formUsing = fn (): array => [RichEditor::make('content')->plugins([HighlightPlugin::make()])];

    $this->postJson('/admin/_alfakit/actions/modal', [
        'token' => FormsBPage::token($user),
        'component' => 'field:form::content',
        'action' => 'highlightColor',
        'arguments' => ['editorSelection' => ['from' => 1, 'to' => 4], 'attributes' => ['color' => '#ff0']],
        'state' => ['content' => '<p>x</p>'],
    ])->assertOk()->assertJsonPath('form.state.color', '#ff0');

    $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($user),
        'component' => 'field:form::content',
        'action' => 'highlightColor',
        'arguments' => ['editorSelection' => ['from' => 1, 'to' => 4]],
        'state' => ['content' => '<p>x</p>'],
        'data' => ['color' => '#0f0'],
    ])->assertOk()->assertJsonPath('result', [
        'editorCommands' => [['setMark', 'highlight', ['color' => '#0f0']]],
        'editorSelection' => ['from' => 1, 'to' => 4],
    ]);

    // A tool without `action()` has no action to run.
    FormsBPage::$formUsing = fn (): array => [RichEditor::make('content')->plugins([HighlightPlugin::make()])->disabled()];

    $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($user),
        'component' => 'field:form::content',
        'action' => 'highlightColor',
        'state' => ['content' => '<p>x</p>'],
        'data' => ['color' => '#0f0'],
    ])->assertStatus(403);
});

it('extends the sanitiser and JSON rendering with plugins', function (): void {
    $html = '<aside data-callout="tip" onclick="x()">Tip</aside><script>alert(1)</script>';

    expect(RichContentRenderer::make($html)->toHtml())->not->toContain('<aside')
        ->and(RichContentRenderer::make($html)->plugins([HighlightPlugin::make()])->toHtml())->toBe('<aside data-callout="tip">Tip</aside>');

    $json = ['type' => 'doc', 'content' => [
        ['type' => 'callout', 'attrs' => ['tone' => '"><script>'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi', 'marks' => [['type' => 'shout']]]]]]],
        ['type' => 'unknown', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kept']]]]],
    ]];

    expect(RichContentRenderer::make($json)->plugins([HighlightPlugin::make()])->toHtml())
        ->toBe('<aside data-callout="&quot;&gt;&lt;script&gt;"><p><strong data-shout>Hi</strong></p></aside><p>Kept</p>')
        ->and(RichContentRenderer::make($json)->toHtml())->toBe('<p>Hi</p><p>Kept</p>');
});

it('serialises editor commands', function (): void {
    expect(json_encode(EditorCommand::make('setMark', 'highlight', ['color' => '#fff'])))->toBe('["setMark","highlight",{"color":"#fff"}]')
        ->and(RichEditorTool::make('fooBar')->toSchema())->toBe(['name' => 'fooBar', 'label' => 'Foo Bar']);
});

// ---------------------------------------------------------------- Rich content models

it('configures editors and renders content from the model', function (): void {
    Storage::fake('attachments');
    Storage::disk('attachments')->buildTemporaryUrlsUsing(fn (string $path): string => Storage::disk('attachments')->url($path).'?signature=1');

    $stable = Storage::disk('attachments')->url('posts/01J0000000000000000000000Z.png');
    $post = RichPost::query()->create([
        'title' => 'Hello',
        'slug' => 'hello',
        'content' => '<p>Hi <span data-type="mergeTag" data-id="name">{{ name }}</span></p><img src="'.$stable.'"><div data-type="customBlock" data-id="hero" data-config="{&quot;heading&quot;:&quot;Big&quot;}"></div><script>x</script>',
    ]);

    $editor = RichEditor::make('content');
    $props = schema([$editor], $post, operation: 'edit')->fill()->toSchema()[0];

    expect($editor->getFileAttachmentsDiskName())->toBe('attachments')
        ->and($editor->getFileAttachmentsDirectory())->toBe('posts')
        ->and($editor->getFileAttachmentsVisibility())->toBe('private')
        ->and($props['customBlocks'][0])->toMatchArray(['id' => 'hero', 'group' => 'Marketing'])
        ->and($props['mergeTags'])->toBe([['id' => 'name', 'label' => 'Full name'], ['id' => 'today', 'label' => 'today']])
        ->and($props['extensions'])->toBe(['highlight-color'])
        ->and($props['fileAttachments']['disk'])->toBe('attachments');

    $html = $post->renderRichContent('content');

    expect($html)->toContain('Hi Jane &lt;Doe&gt;')
        ->and($html)->toContain('<section class="hero"><h2>Big</h2></section>')
        ->and($html)->toContain(htmlspecialchars($stable.'?signature=1'))
        ->and($html)->not->toContain('<script')
        ->and((string) $post->getRichContentAttribute('content'))->toBe($html)
        ->and($post->hasRichContentAttribute('title'))->toBeFalse()
        ->and($post->getRichContentAttribute('title'))->toBeNull()
        // Set up once per model instance.
        ->and(RichPost::$setUps)->toBe(1);
});

it('lets the field override the model and reads the model on create pages', function (): void {
    $editor = RichEditor::make('content')->fileAttachmentsDisk('public')->mergeTags(['other'])->customBlocks([]);
    $props = schema([$editor], model: RichPost::class)->fill()->toSchema()[0];

    expect($editor->getFileAttachmentsDiskName())->toBe('public')
        ->and($editor->getFileAttachmentsDirectory())->toBe('posts')
        ->and($props['mergeTags'])->toBe([['id' => 'other', 'label' => 'Other']])
        ->and($props)->not->toHaveKey('customBlocks');

    // Models without rich content keep the defaults.
    $plain = RichEditor::make('content');
    schema([$plain], model: Post::class)->fill()->toSchema();

    expect($plain->getRichContentAttribute())->toBeNull()
        ->and($plain->getFileAttachmentsVisibility())->toBe('public');
});

it('renders plain models without registered content', function (): void {
    $post = new RichPost(['content' => '<p onclick="x">Hi</p>']);

    expect($post->renderRichContent('title'))->toBe('')
        ->and(RichContentRenderer::make('<p onclick="x">Hi</p>')->toHtml())->toBe('<p>Hi</p>');
});

it('signs private attachments in the renderer, scoped to the tenant directory', function (): void {
    Storage::fake('attachments');
    Storage::disk('attachments')->buildTemporaryUrlsUsing(fn (string $path): string => Storage::disk('attachments')->url($path).'?signature=1');

    $mine = Storage::disk('attachments')->url('posts/01J0000000000000000000000Z.png');
    $elsewhere = Storage::disk('attachments')->url('other/01J0000000000000000000000Z.png');
    $html = RichContentRenderer::make('<img src="'.$mine.'"><img src="'.$elsewhere.'">')
        ->fileAttachments('attachments', 'posts', 'private')
        ->toHtml();

    expect($html)->toContain(htmlspecialchars($mine.'?signature=1'))
        ->and($html)->not->toContain(htmlspecialchars($elsewhere.'?signature=1'))
        ->and(RichContentRenderer::make('<img src="'.$mine.'">')->fileAttachments('attachments', 'posts', 'public')->toHtml())->not->toContain('signature');
});

it('signs attachments on a local disk without a public url (Laravel\'s default `local` disk) like the editor', function (): void {
    config(['filesystems.disks.privatelocal' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => true]]);
    Storage::fake('privatelocal');
    Storage::disk('privatelocal')->buildTemporaryUrlsUsing(fn (string $path): string => '/storage/'.$path.'?signature=1');

    $html = RichContentRenderer::make('<img src="/storage/posts/01J0000000000000000000000Z.png">')
        ->fileAttachments('privatelocal', 'posts')
        ->toHtml();

    expect($html)->toContain('01J0000000000000000000000Z.png?signature=1')
        ->and(RichEditor::make('content')->fileAttachmentsDisk('privatelocal')->getFileAttachmentsVisibility())->toBe('private');
});

// ---------------------------------------------------------------- Fakers

it('fakes rich content as storage HTML, JSON, Markdown and text', function (): void {
    $faker = RichContentFaker::make(Factory::create())
        ->heading('Title')
        ->paragraphs(2, links: true, bold: true)
        ->bulletList(2)
        ->orderedList(2)
        ->blockquote('Quote')
        ->codeBlock('echo 1;', 'php')
        ->table(2, 2)
        ->details('More')
        ->image('https://example.com/a.png', 'Alt', 300)
        ->mergeTag('name')
        ->mention('5', 'Jane')
        ->textColor('#dc2626')
        ->textAlignment('center')
        ->hardBreak()
        ->horizontalRule()
        ->customBlock(HeroBlock::class, ['heading' => 'Hi']);

    $html = $faker->toHtml();

    expect($html)->toContain('<h2>Title</h2>')
        ->and($html)->toContain('<strong>')
        ->and($html)->toContain('<a href="http')
        ->and($html)->toContain('<span data-type="mergeTag" data-id="name">{{ name }}</span>')
        ->and($html)->toContain('data-label="Jane" data-char="@"')
        ->and($html)->toContain('<div data-type="customBlock" data-id="hero" data-config="{&quot;heading&quot;:&quot;Hi&quot;}"></div>')
        ->and($html)->toContain('<img src="https://example.com/a.png" alt="Alt" width="300">')
        ->and($html)->toContain('style="text-align: center"')
        ->and($html)->toContain('<table>')
        ->and($faker->toArray()['type'])->toBe('doc')
        ->and(json_decode($faker->toJson(), true))->toBe($faker->toArray())
        ->and($faker->toRenderedHtml(RichContentRenderer::make($faker->toArray())->customBlocks([HeroBlock::class])->mergeTags(['name' => 'Jane'])))
        ->toContain('<section class="hero"><h2>Hi</h2></section>')->toContain('Jane')
        ->and($faker->toText())->toContain('Title')->not->toContain('<');

    $markdown = $faker->toMarkdown();

    expect($markdown)->toContain('## Title')
        ->and($markdown)->toContain('**')
        ->and($markdown)->toContain("```php\necho 1;\n```")
        ->and($markdown)->toContain('> Quote')
        ->and($markdown)->toContain('| --- | --- |')
        ->and($markdown)->toContain('![Alt](https://example.com/a.png)')
        ->and($markdown)->toContain('{{ name }}');
});

it('round-trips faked storage HTML through the editor field', function (): void {
    $html = RichContentFaker::make()->paragraphs(3)->toHtml();
    $schema = schema([RichEditor::make('content')])->fill(['content' => $html]);

    expect($schema->getState()['content'])->toBe($html)
        ->and(substr_count($html, '<p>'))->toBe(3);
});

it('fakes Markdown reproducibly', function (): void {
    $make = function (): string {
        $faker = Factory::create();
        $faker->seed(42);

        return MarkdownFaker::make($faker)->heading()->paragraphs(2, links: true, bold: true, code: true)->bulletList()->orderedList()->blockquote()->codeBlock('php')->table()->image()->horizontalRule()->toMarkdown();
    };

    $markdown = $make();

    expect($markdown)->toBe($make())
        ->and($markdown)->toStartWith('## ')
        ->and($markdown)->toContain("\n- ")
        ->and($markdown)->toContain("\n1. ")
        ->and($markdown)->toContain('```php')
        ->and($markdown)->toContain('](http')
        ->and(MarkdownFaker::make()->heading('Hi')->toHtml())->toBe("<h2>Hi</h2>\n")
        ->and((string) MarkdownFaker::make()->paragraph('Plain'))->toBe("Plain\n");
});

it('keeps model attachments in the tenant folder for the editor and the renderer', function (): void {
    Storage::fake('attachments');
    Storage::disk('attachments')->buildTemporaryUrlsUsing(fn (string $path): string => Storage::disk('attachments')->url($path).'?signature=1');

    $panel = AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels::register('rich', fn (AlfaSix\Alfakit\Panel $panel): AlfaSix\Alfakit\Panel => $panel->tenant(AlfaSix\Alfakit\Tests\Fixtures\Models\Team::class, slugAttribute: 'slug')->tenantUploadDirectory());
    $team = AlfaSix\Alfakit\Tests\Fixtures\Models\Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    alfakit()->setCurrentPanel($panel);
    alfakit()->setTenant($team);

    $editor = RichEditor::make('content');
    schema([$editor], model: RichPost::class)->fill()->toSchema();

    $own = Storage::disk('attachments')->url("tenants/{$team->id}/posts/01J0000000000000000000000Z.png");
    $foreign = Storage::disk('attachments')->url('tenants/999/posts/01J0000000000000000000000Z.png');
    $post = new RichPost(['content' => '<img src="'.$own.'"><img src="'.$foreign.'">']);
    $html = $post->renderRichContent('content');

    expect($editor->getFileAttachmentsDirectory())->toBe("tenants/{$team->id}/posts")
        ->and($html)->toContain(htmlspecialchars($own.'?signature=1'))
        ->and($html)->not->toContain(htmlspecialchars($foreign.'?signature=1'));
});
