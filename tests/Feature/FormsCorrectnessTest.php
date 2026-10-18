<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentUrls;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\PostWithTagsForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * @return array<string, array<string>>
 */
function formErrors(Schema $schema): array
{
    try {
        $schema->validate();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

// ---------------------------------------------------------------- unique()

it('ignores the bound record in unique() by default, unless ignoreRecord is false', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    Post::query()->create(['title' => 'Other', 'slug' => 'other']);

    $edit = fn (string $slug, bool $ignore = true): Schema => schema([TextInput::make('slug')->unique(ignoreRecord: $ignore)], record: $post, operation: 'edit')
        ->fill()->applyClientState(['slug' => $slug]);

    expect(formErrors(schema([TextInput::make('slug')->unique()], record: $post, operation: 'edit')->fill()))->toBe([])
        ->and(formErrors($edit('other')))->toHaveKey('slug')
        ->and(formErrors($edit('hello', ignore: false)))->toHaveKey('slug')
        ->and(formErrors(schema([TextInput::make('slug')->unique()], model: Post::class)->fill()->applyClientState(['slug' => 'hello'])))->toHaveKey('slug');
});

it('only ignores the bound record in unique() on its own table', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    Tag::query()->forceCreate(['id' => $post->id, 'name' => 'php']);

    $schema = schema([TextInput::make('label')->unique('tags', 'name')], record: $post, operation: 'edit')
        ->fill()->applyClientState(['label' => 'php']);

    expect(formErrors($schema))->toHaveKey('label');
});

it('keeps unique() tenant-scoped while ignoring the bound record', function (): void {
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $project = Project::query()->create(['team_id' => $alpha->id, 'name' => 'Website']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Shared name']);

    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    $field = TextInput::make('name')->unique();
    schema([$field], record: $project, operation: 'edit')->fill();
    $rule = collect($field->getValidationRules())->first(fn (mixed $rule): bool => $rule instanceof Illuminate\Validation\Rules\Unique);

    expect((string) $rule)->toContain('team_id')->toContain((string) $alpha->id)->toContain((string) $project->id);

    $schema = fn (string $name): Schema => schema([TextInput::make('name')->unique()], record: $project, operation: 'edit')->fill()->applyClientState(['name' => $name]);

    expect(formErrors($schema('Website')))->toBe([])
        ->and(formErrors($schema('Shared name')))->toBe([]);
});

// ---------------------------------------------------------------- distinct()

it('fails distinct() on duplicate values across repeater items, on the duplicate items', function (): void {
    $repeater = fn (): Repeater => Repeater::make('items')->schema([
        TextInput::make('sku')->distinct(),
        TextInput::make('note'),
    ]);

    $duplicate = schema([$repeater()])->fill()->applyClientState(['items' => [['sku' => 'A', 'note' => 'x'], ['sku' => 'B', 'note' => 'x'], ['sku' => 'A', 'note' => 'y']]]);
    $unique = schema([$repeater()])->fill()->applyClientState(['items' => [['sku' => 'A', 'note' => 'x'], ['sku' => 'B', 'note' => 'x']]]);

    $errors = formErrors($duplicate);

    expect(array_keys($errors))->toBe(['items.0.sku', 'items.2.sku'])
        ->and($errors['items.0.sku'][0])->toBe('The SKU field has a duplicate value.')
        ->and(formErrors($unique))->toBe([]);
});

it('fails distinct() inside nested repeaters per inner list and inside builder blocks', function (): void {
    $nested = Repeater::make('orders')->schema([
        Repeater::make('lines')->schema([TextInput::make('sku')->distinct()]),
    ]);

    $schema = schema([$nested])->fill()->applyClientState(['orders' => [
        ['lines' => [['sku' => 'A'], ['sku' => 'B']]],
        ['lines' => [['sku' => 'A'], ['sku' => 'A']]],
    ]]);

    $builder = Builder::make('content')->blocks([Block::make('link')->schema([TextInput::make('url')->distinct()])]);
    $blocks = schema([$builder])->fill()->applyClientState(['content' => [
        ['type' => 'link', 'data' => ['url' => 'https://a.test']],
        ['type' => 'link', 'data' => ['url' => 'https://a.test']],
    ]]);

    expect(array_keys(formErrors($schema)))->toBe(['orders.1.lines.0.sku', 'orders.1.lines.1.sku'])
        ->and(array_keys(formErrors($blocks)))->toBe(['content.0.data.url', 'content.1.data.url']);
});

// ---------------------------------------------------------------- Private files

it('serves private FileUpload files through temporary URLs', function (): void {
    Storage::fake('private-files');
    Storage::disk('private-files')->buildTemporaryUrlsUsing(fn (string $path): string => "https://signed.test/{$path}?signature=1");
    Storage::disk('private-files')->put('docs/a.pdf', 'pdf');

    $private = FileUpload::make('file')->disk('private-files')->visibility('private');
    $public = FileUpload::make('file')->disk('private-files');

    schema([$private])->fill(['file' => 'docs/a.pdf']);
    schema([$public])->fill(['file' => 'docs/a.pdf']);

    expect($private->getFileUrl('docs/a.pdf'))->toBe('https://signed.test/docs/a.pdf?signature=1')
        ->and($public->getFileUrl('docs/a.pdf'))->not->toContain('signature')
        ->and($private->toSchema()['files'][0]['url'] ?? null)->toBe('https://signed.test/docs/a.pdf?signature=1');

    config(['filesystems.disks.private-files.visibility' => 'private']);

    expect(FileUpload::make('file')->disk('private-files')->getVisibility())->toBe('private');
});

it('signs private rich editor attachments when shown and stores the stable URL', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::disk('attachments')->buildTemporaryUrlsUsing(fn (string $path): string => Storage::disk('attachments')->url($path).'?signature=1');
    config(['alfakit.uploads.temporary_disk' => 'local']);

    $path = 'notes/01J0000000000000000000000Z.png';
    $stable = Storage::disk('attachments')->url($path);
    $content = '<p>Hi</p><img src="'.$stable.'"><img src="https://elsewhere.test/notes/01J0000000000000000000000Z.png">';
    $editor = fn (): RichEditor => RichEditor::make('content')->fileAttachmentsDisk('attachments')->fileAttachmentsDirectory('notes')->fileAttachmentsVisibility('private');

    $schema = schema([$editor()])->fill(['content' => $content]);
    $shown = $schema->getRawState()['content'];

    expect($shown)->toContain(htmlspecialchars($stable.'?signature=1'))
        ->and($shown)->toContain('src="https://elsewhere.test/notes/01J0000000000000000000000Z.png"')
        ->and($schema->getState()['content'])->toBe($content)
        ->and((new FileAttachmentUrls('attachments', 'notes'))->sign(['type' => 'image', 'attrs' => ['src' => $stable]])['attrs']['src'])->toBe($stable.'?signature=1');

    // A new upload is saved with the stable URL too.
    $user = User::query()->create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'password']);
    $this->actingAs($user);
    $key = (string) $this->post('/admin/_alfakit/uploads', ['file' => UploadedFile::fake()->image('photo.png', 4, 4)], ['Accept' => 'application/json'])->assertOk()->json('key');
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    $saved = schema([$editor()])->fill()->rawState(['content' => '<p>x</p><img src="'.$key.'">'])->validate()['content'];

    expect($saved)->not->toContain('signature')->not->toContain('tmp:')->toContain(Storage::disk('attachments')->url('notes/'));
});

// ---------------------------------------------------------------- CheckboxList, Toggle, shortcuts

it('lays CheckboxList options out with columns()', function (): void {
    $list = CheckboxList::make('roles')->options(['a' => 'A', 'b' => 'B'])->columns(3);
    $responsive = CheckboxList::make('roles')->options(['a' => 'A'])->columns(['default' => 1, 'md' => 2, 'lg' => 4]);
    $closure = CheckboxList::make('roles')->options(['a' => 'A'])->columns(fn (): int => 2);

    schema([$list]);
    schema([$responsive]);
    schema([$closure]);

    expect($list->toSchema()['gridColumns'])->toBe(3)
        ->and($responsive->toSchema()['gridColumns'])->toBe(4)
        ->and($closure->toSchema()['gridColumns'])->toBe(2);
});

it('supports declined() on Toggle', function (): void {
    $on = schema([Toggle::make('opt_out')->declined()])->fill()->applyClientState(['opt_out' => true]);
    $off = schema([Toggle::make('opt_out')->declined()])->fill()->applyClientState(['opt_out' => false]);

    expect(formErrors($on))->toHaveKey('opt_out')
        ->and(formErrors($off))->toBe([]);
});

it('adds Filament validation shortcuts with field references resolved like same()', function (): void {
    $field = fn (string $name): TextInput => TextInput::make($name);

    $schema = schema([
        Repeater::make('items')->schema([
            $field('min')->numeric(),
            $field('max')->numeric()->gt('min')->gte('min')->multipleOf(5),
            $field('code')->alphaDash()->alphaNum()->alpha()->ascii()->string()->notRegex('/x/'),
            $field('color')->hexColor(),
            $field('ip4')->ipv4(),
            $field('ip6')->ipv6(),
            $field('mac')->macAddress(),
            $field('low')->numeric()->lt('max')->lte('max'),
            $field('a')->requiredWithAll(['min', 'max'])->prohibits('b'),
            $field('b')->requiredWithoutAll(['a', 'min'])->prohibitedIf('min', 1)->prohibitedUnless('min', 2),
            Toggle::make('agree'),
            $field('reason')->requiredIfAccepted('agree'),
            $field('status')->enum(AlfaSix\Alfakit\Support\Enums\Color::class),
        ]),
    ]);

    $schema->fill()->applyClientState(['items' => [[
        'min' => 10, 'max' => 7, 'code' => 'ab-x', 'color' => 'red', 'ip4' => '::1', 'ip6' => '1.2.3.4', 'mac' => 'nope',
        'low' => 9, 'a' => 'filled', 'b' => 'filled', 'agree' => true, 'reason' => null, 'status' => 'nope',
    ]]]);

    $rules = $schema->getValidator()->getRules();

    expect($rules['items.0.max'])->toContain('gt:items.0.min', 'gte:items.0.min', 'multiple_of:5')
        ->and($rules['items.0.low'])->toContain('lt:items.0.max', 'lte:items.0.max')
        ->and($rules['items.0.a'])->toContain('required_with_all:items.0.min,items.0.max', 'prohibits:items.0.b')
        ->and($rules['items.0.b'])->toContain('required_without_all:items.0.a,items.0.min', 'prohibited_if:items.0.min,"1"', 'prohibited_unless:items.0.min,"2"')
        ->and($rules['items.0.reason'])->toContain('required_if_accepted:items.0.agree')
        ->and(array_keys(formErrors($schema)))->toBe([
            'items.0.max', 'items.0.code', 'items.0.color', 'items.0.ip4', 'items.0.ip6', 'items.0.mac',
            'items.0.low', 'items.0.a', 'items.0.b', 'items.0.reason', 'items.0.status',
        ]);

    expect(formErrors(schema([TextInput::make('slug')->alphaDash(), TextInput::make('url')->filled()])->fill()->applyClientState(['slug' => 'ok-slug_1', 'url' => 'x'])))->toBe([])
        ->and(formErrors(schema([TextInput::make('v')->numeric()->gt(5)])->fill()->applyClientState(['v' => 3])))->toHaveKey('v');
});

// ---------------------------------------------------------------- Standalone forms

it('saves relationship fields of a standalone create form to the record submit() returns', function (): void {
    Route::middleware('web')->get('/new-post', fn () => Inertia::render('NewPost', ['form' => PostWithTagsForm::make()]));
    $php = Tag::query()->create(['name' => 'php']);
    $js = Tag::query()->create(['name' => 'js']);

    $token = inertiaProps(inertiaGet($this, '/new-post'))['form']['token'];

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['title' => 'Tagged', 'tags' => [(string) $php->id, (string) $js->id]]])
        ->assertRedirect();

    $post = Post::query()->where('title', 'Tagged')->firstOrFail();

    expect($post->tags()->pluck('name')->sort()->values()->all())->toBe(['js', 'php']);
});
