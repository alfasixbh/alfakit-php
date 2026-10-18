<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\Pages\MultiFactorChallenge as MultiFactorChallengePage;
use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\OneTimeCodeInput;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Contracts\CanGenerateFakeConfiguration;
use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\MentionProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\RichContentPlugin;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentAttribute;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentCustomBlock;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentFaker;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Support\TemporaryUrls;
use AlfaSix\Alfakit\Tables\Columns\ImageColumn;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks\HeroBlock;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Faker\Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.default_filesystem_disk' => 'public']);
});

function zUploadKey(): string
{
    $user = User::query()->firstOrCreate(['email' => 'z@example.com'], ['name' => 'Z', 'password' => 'password']);
    test()->actingAs($user);
    $key = (string) test()->post('/admin/_alfakit/uploads', ['file' => UploadedFile::fake()->image('photo.png', 4, 4)], ['Accept' => 'application/json'])->assertOk()->json('key');
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    return $key;
}

final class ZProseBlock extends RichContentCustomBlock implements CanGenerateFakeConfiguration
{
    public static function toPreviewHtml(array $config): string
    {
        return '<h2>'.e($config['title'] ?? '').'</h2>';
    }

    public static function shouldApplyProseStylingToPreview(array $config): bool
    {
        return ($config['prose'] ?? false) === true;
    }

    public static function generateFakeConfiguration(Generator $faker): array
    {
        return ['title' => 'Generated', 'prose' => true];
    }
}

final class ZRecordingProvider implements FileAttachmentProvider
{
    /** @var list<string> */
    public array $saved = [];

    /** @var list<array<string>> */
    public array $cleanUps = [];

    public bool $requiresRecord = false;

    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    public function saveUploadedFileAttachment(TemporaryUpload $file): ?string
    {
        return $this->saved[] = 'https://media.test/'.count($this->saved).'.png';
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        return is_string($file) && str_starts_with($file, 'https://media.test/') ? $file.'?signed=1' : null;
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return 'private';
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return $this->requiresRecord;
    }

    public function cleanUpFileAttachments(array $exceptIds): void
    {
        $this->cleanUps[] = $exceptIds;
    }
}

final class ZProviderPlugin extends RichContentPlugin implements HasFileAttachmentProvider
{
    public function __construct(public ZRecordingProvider $provider) {}

    public function getFileAttachmentProvider(): ?FileAttachmentProvider
    {
        return $this->provider;
    }
}

// ---------------------------------------------------------------- A15 attachment tampering

it('drops rich editor attachments that the stored content never referenced', function (): void {
    $own = Storage::disk('public')->url('notes/01J0000000000000000000000A.png');
    $foreign = Storage::disk('public')->url('notes/01J0000000000000000000000B.png');
    $post = Post::query()->create(['title' => 'T', 'slug' => 't', 'content' => '<p>x</p><img src="'.$own.'">']);
    $editor = fn (): RichEditor => RichEditor::make('content')->fileAttachmentsDirectory('notes');

    $saved = schema([$editor()], $post, operation: 'edit')->fill()
        ->applyClientState(['content' => '<p>x</p><img src="'.$own.'"><img src="'.$foreign.'"><img src="https://elsewhere.test/a.png">'])
        ->getState()['content'];

    expect($saved)->toContain($own)->not->toContain($foreign)->toContain('https://elsewhere.test/a.png');

    $allowed = schema([$editor()->preventFileAttachmentTampering(allowFilePathUsing: fn (string $file): bool => $file === 'notes/01J0000000000000000000000B.png')], $post, operation: 'edit')
        ->fill()->applyClientState(['content' => '<img src="'.$foreign.'">'])->getState()['content'];
    $off = schema([$editor()->preventFileAttachmentTampering(false)], $post, operation: 'edit')
        ->fill()->applyClientState(['content' => '<img src="'.$foreign.'">'])->getState()['content'];

    expect($allowed)->toContain($foreign)
        ->and($off)->toContain($foreign)
        ->and($editor()->shouldPreventFileAttachmentTampering())->toBeTrue();
});

it('keeps images uploaded while editing when tampering protection is on', function (): void {
    $key = zUploadKey();

    $saved = schema([RichEditor::make('content')->fileAttachmentsDirectory('notes')])->fill()
        ->applyClientState(['content' => '<p>x</p><img src="'.$key.'">'])->getState()['content'];

    expect($saved)->toContain(Storage::disk('public')->url('notes/'))->not->toContain('tmp:');
});

it('checks JSON documents for tampered attachments too', function (): void {
    $foreign = Storage::disk('public')->url('01J0000000000000000000000C.png');
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi']]], ['type' => 'image', 'attrs' => ['src' => $foreign]]]];

    $saved = schema([RichEditor::make('content')->json()])->fill()->applyClientState(['content' => $document])->getState()['content'];

    expect($saved['content'])->toHaveCount(1)->and($saved['content'][0]['type'])->toBe('paragraph');
});

// ---------------------------------------------------------------- A14 temporary URL expiry

it('makes the temporary URL expiry configurable and rounds it to the end of the hour', function (): void {
    Carbon::setTestNow('2026-01-01 10:10:00');
    $expirations = [];
    Storage::disk('public')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration) use (&$expirations): string {
        $expirations[] = $expiration->format('H:i');

        return 'https://signed.test/'.$path;
    });

    config(['alfakit.temporary_file_url_expiry_minutes' => 120]);

    $upload = FileUpload::make('file')->disk('public')->visibility('private');
    $upload->getFileUrl('a.pdf');
    $upload->temporaryUrlExpiry(5)->getFileUrl('a.pdf');
    (new ImageColumn('image'))->disk('public')->visibility('private')->temporaryUrlExpiry(90)->getImageUrl('a.png');

    expect($expirations)->toBe(['12:59', '10:59', '11:59'])
        ->and(TemporaryUrls::defaultExpiryMinutes())->toBe(120);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------- A16 prose previews

it('tells the editor whether a custom block preview gets prose styling', function (): void {
    $editor = RichEditor::make('content')->customBlocks([ZProseBlock::class, HeroBlock::class]);
    schema([$editor]);

    expect($editor->shouldApplyProseStylingToCustomBlockPreview(ZProseBlock::getId(), ['prose' => true]))->toBeTrue()
        ->and($editor->shouldApplyProseStylingToCustomBlockPreview(ZProseBlock::getId(), []))->toBeFalse()
        ->and(HeroBlock::shouldApplyProseStylingToPreview([]))->toBeFalse();
});

// ---------------------------------------------------------------- A20 / A31 Builder picker

it('serialises the builder search texts and grouped blocks', function (): void {
    $builder = Builder::make('content')
        ->searchable()
        ->searchPrompt('Find a block')
        ->noSearchResultsMessage('Nothing here')
        ->blocks([
            Block::make('note'),
            'Content' => [Block::make('heading'), Block::make('paragraph')],
            'Media' => [Block::make('video'), Block::make('image')->group('Pictures')],
        ]);
    schema([$builder]);
    $props = $builder->toSchema();

    expect($props['blockSearchPrompt'])->toBe('Find a block')
        ->and($props['blockNoSearchResultsMessage'])->toBe('Nothing here')
        ->and(array_column($props['blocks'], 'name'))->toBe(['note', 'heading', 'paragraph', 'video', 'image'])
        ->and(array_map(fn (array $block): ?string => $block['group'] ?? null, $props['blocks']))->toBe([null, 'Content', 'Content', 'Media', 'Pictures'])
        ->and(Builder::make('b')->getSearchPrompt())->toBeNull();

    $lazy = Builder::make('content')->blocks(fn (): array => ['Group' => [Block::make('one')]]);
    schema([$lazy]);

    expect($lazy->toSchema()['blocks'][0]['group'])->toBe('Group');
});

// ---------------------------------------------------------------- A1 mention labels

it('renders mentions with their provider\'s current label, falling back to the stored one', function (): void {
    $provider = MentionProvider::make('@')->getLabelsUsing(fn (array $ids): array => array_intersect_key(['1' => 'Jane Renamed'], array_flip($ids)));
    $html = '<p><span data-type="mention" data-id="1">@Jane</span> <span data-type="mention" data-id="2">@Old</span></p>';
    $json = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'mention', 'attrs' => ['id' => '1', 'label' => 'Jane', 'char' => '@']],
        ['type' => 'mention', 'attrs' => ['id' => '3', 'char' => '@']],
    ]]]];

    expect(RichContentRenderer::make($html)->mentions([$provider])->toHtml())->toContain('@Jane Renamed')->toContain('@Old')
        ->and(RichContentRenderer::make($json)->mentions([$provider])->toHtml())->toContain('@Jane Renamed')->toContain('@3');

    $editor = RichEditor::make('content')->mentions([$provider]);
    $shown = schema([$editor])->fill(['content' => '<p><span data-id="1" data-label="Jane" data-char="@" data-type="mention">@Jane</span></p>'])->getRawState()['content'];

    expect($shown)->toContain('data-label="Jane Renamed"')->toContain('@Jane Renamed');
});

// ---------------------------------------------------------------- A23 faker and link protocols

it('registers the rich content faker on Faker', function (): void {
    $attribute = (new Post)->forceFill(['title' => 'x']);
    $rich = new RichContentAttribute($attribute, 'content');
    $rich->customBlocks([ZProseBlock::class])->json();

    expect(fake()->alfakitRichContent())->toBeInstanceOf(RichContentFaker::class)
        ->and(fake()->filamentRichContent()->paragraphs(1)->toHtml())->toStartWith('<p>')
        ->and(fake()->filamentMarkdown()->heading('Hi')->toMarkdown())->toContain('## Hi');

    $document = fake()->filamentRichContent($rich)->customBlock()->toValue();

    expect($document['content'][0]['attrs'])->toBe(['id' => ZProseBlock::getId(), 'config' => ['title' => 'Generated', 'prose' => true]])
        ->and(fn () => RichContentFaker::make()->customBlock())->toThrow(LogicException::class);
});

it('limits link schemes with linkProtocols()', function (): void {
    $html = '<p><a href="https://a.test">A</a> <a href="ftp://b.test">B</a> <a href="/c">C</a></p>';

    $saved = schema([RichEditor::make('content')->linkProtocols(['https'])])->fill()->applyClientState(['content' => $html])->getState()['content'];

    expect($saved)->toContain('href="https://a.test"')->not->toContain('ftp://')->toContain('href="/c"')
        ->and(RichContentRenderer::make('<p><a href="ftp://b.test">B</a></p>')->linkProtocols(['ftp'])->toHtml())->toContain('ftp://b.test')
        ->and(RichContentRenderer::make('<p><a href="ftp://b.test">B</a></p>')->toHtml())->not->toContain('ftp://')
        ->and(RichContentRenderer::normalizeLinkProtocols(['HTTPS:', 'javascript', 'bad scheme']))->toBe(['https']);

    $editor = RichEditor::make('content')->linkProtocols(['https', 'mailto']);
    schema([$editor]);

    expect($editor->toSchema()['linkProtocols'])->toBe(['https', 'mailto']);
});

// ---------------------------------------------------------------- A7 / A25 toolbar

it('adds a grid floating toolbar with column controls by default', function (): void {
    $editor = RichEditor::make('content')->toolbarButtons(['h4', 'h5', 'h6', 'paragraph', 'grid']);
    schema([$editor]);
    $custom = RichEditor::make('content')->toolbarButtons(['grid'])->floatingToolbars(['grid' => ['gridDelete']]);
    schema([$custom]);

    expect($editor->toSchema()['floatingToolbars']['grid'])->toBe(RichEditor::DEFAULT_GRID_FLOATING_TOOLBAR)
        ->and($editor->toSchema()['toolbarButtons'])->toBe(['h4', 'h5', 'h6', 'paragraph', 'grid'])
        ->and($custom->toSchema()['floatingToolbars'])->toBe(['grid' => ['gridDelete']]);
});

// ---------------------------------------------------------------- A26 OneTimeCodeInput

it('serialises and validates OneTimeCodeInput', function (): void {
    $input = OneTimeCodeInput::make('code')->length(4)->submitOnCompletion();
    schema([$input]);

    expect($input->toSchema())->toMatchArray(['type' => 'OneTimeCodeInput', 'length' => 4, 'submitOnCompletion' => true])
        ->and(OneTimeCodeInput::make('code')->shouldSubmitOnCompletion())->toBeFalse()
        ->and(OneTimeCodeInput::make('code')->getLength())->toBe(6);

    $errors = function (mixed $code): array {
        try {
            schema([OneTimeCodeInput::make('code')->length(4)])->fill()->applyClientState(['code' => $code])->validate();
        } catch (Illuminate\Validation\ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    expect($errors('12a4'))->toHaveKey('code')
        ->and($errors('123'))->toHaveKey('code')
        ->and($errors('1234'))->toBe([]);
});

it('uses OneTimeCodeInput for multi-factor codes', function (): void {
    $source = file_get_contents((new ReflectionClass(MultiFactorChallengePage::class))->getFileName());

    expect($source)->toContain('OneTimeCodeInput::make(\'code\')')
        ->and(AlfaSix\Alfakit\Migration\FilamentClassMap::classes()['Filament\\Forms\\Components\\OneTimeCodeInput'] ?? null)->toBe(OneTimeCodeInput::class);
})->skip(fn (): bool => ! method_exists(AlfaSix\Alfakit\Migration\FilamentClassMap::class, 'classes'), 'class map accessor differs');

// ---------------------------------------------------------------- A5 file attachment providers

it('stores attachments with a custom file attachment provider', function (): void {
    $provider = new ZRecordingProvider;
    $key = zUploadKey();
    $editor = RichEditor::make('content')->fileAttachmentProvider($provider);
    $post = Post::query()->create(['title' => 'T', 'slug' => 't2']);
    $form = schema([$editor], $post, operation: 'edit')->fill()->applyClientState(['content' => '<p>x</p><img src="'.$key.'">']);

    $saved = $form->getState()['content'];
    $form->saveRelationships();

    expect($saved)->toContain('https://media.test/0.png')
        ->and($editor->getFileAttachmentsVisibility())->toBe('private')
        ->and($provider->cleanUps)->toBe([['https://media.test/0.png']])
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(RichContentRenderer::make($saved)->fileAttachmentProvider($provider)->toHtml())->toContain('https://media.test/0.png?signed=1')
        ->and(RichContentRenderer::make($saved)->plugins([new ZProviderPlugin($provider)])->toHtml())->toContain('?signed=1');
});

it('takes the file attachment provider from a plugin and defers storing until the record exists', function (): void {
    $provider = new ZRecordingProvider;
    $provider->requiresRecord = true;
    $key = zUploadKey();
    $editor = RichEditor::make('content')->plugins([new ZProviderPlugin($provider)]);
    $form = schema([$editor], model: Post::class)->fill()->applyClientState(['content' => '<p>x</p><img src="'.$key.'">']);

    $content = $form->getState()['content'];

    expect($content)->toContain('tmp:')->and($provider->saved)->toBe([]);

    $post = Post::query()->create(['title' => 'T', 'slug' => 't3', 'content' => $content]);
    $form->record($post)->saveRelationships();

    expect($post->fresh()->content)->toContain('https://media.test/0.png')->not->toContain('tmp:');
});

it('keeps the default disk provider', function (): void {
    $editor = RichEditor::make('content')->fileAttachmentsDirectory('posts');
    schema([$editor]);

    expect($editor->getFileAttachmentProvider())->toBeNull()
        ->and($editor->getDefaultFileAttachmentProvider())->toBeInstanceOf(RichEditor\FileAttachmentProviders\DiskFileAttachmentProvider::class)
        ->and(TextInput::make('x'))->toBeInstanceOf(TextInput::class);
});
