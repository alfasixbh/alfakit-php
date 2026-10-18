<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.default_filesystem_disk' => 'public']);
});

function toolbarUser(string $email = 'toolbar@example.com'): User
{
    return User::query()->create(['name' => 'Toolbar', 'email' => $email, 'password' => 'password']);
}

function toolbarUpload(UploadedFile $file): string
{
    return (string) test()->post('/admin/_alfakit/uploads', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');
}

function toolbarActAs(User $user): void
{
    test()->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
}

// ---------------------------------------------------------------- Sanitiser round-trip

it('keeps what the new toolbar buttons produce through the sanitiser', function (string $html): void {
    expect(Html::sanitize($html))->toBe($html);
})->with([
    'text colour' => '<p><span data-color="#dc2626" style="color: #dc2626">red</span></p>',
    'highlight' => '<p><mark>marked</mark></p>',
    'sub and superscript' => '<p>H<sub>2</sub>O and x<sup>2</sup></p>',
    'inline code' => '<p><code>$x</code></p>',
    'alignment' => '<p style="text-align: center">c</p><h2 style="text-align: end">e</h2><p style="text-align: justify">j</p>',
    'table' => '<table><tbody><tr><th colspan="2"><p>Head</p></th></tr><tr><td><p>a</p></td><td rowspan="2"><p>b</p></td></tr></tbody></table>',
    'details' => '<details><summary>More</summary><div data-type="detailsContent"><p>Hidden</p></div></details>',
]);

it('strips scripts, handlers and unsafe styles from rich content', function (): void {
    $html = '<p style="position: fixed; inset: 0; color: red" onclick="alert(1)">a</p>'
        .'<span style="background-image: url(https://evil.test/x.png); color: #fff">b</span>'
        .'<span data-color="red;background:url(x)" style="color: expression(alert(1))">c</span>'
        .'<p style="text-align: center; behavior: url(x.htc)">d</p>'
        .'<details ontoggle="alert(1)" open><summary onclick="x()">s</summary><script>alert(1)</script></details>';

    $clean = Html::sanitize($html);

    expect($clean)
        ->toContain('<p style="color: red">a</p>')
        ->toContain('<span style="color: #fff">b</span>')
        ->toContain('<span>c</span>')
        ->toContain('<p style="text-align: center">d</p>')
        ->toContain('<details open><summary>s</summary></details>')
        ->not->toContain('onclick')->not->toContain('ontoggle')->not->toContain('url(')
        ->not->toContain('expression')->not->toContain('position')->not->toContain('<script');
});

it('recognises safe colours only', function (string $color, bool $safe): void {
    expect(Html::isSafeColor($color))->toBe($safe);
})->with([
    ['#fff', true], ['#dc2626', true], ['#dc262680', true], ['rgb(220, 38, 38)', true],
    ['hsl(0deg 72% 51% / 0.5)', true], ['rebeccapurple', true],
    ['#ggg', false], ['url(x)', false], ['red; position: fixed', false], ['var(--x)', false],
    ['expression(alert(1))', false], ['', false],
]);

// ---------------------------------------------------------------- RichContentRenderer

it('renders the new marks and nodes from JSON content', function (): void {
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [
            ['type' => 'text', 'text' => 'red', 'marks' => [['type' => 'textColor', 'attrs' => ['color' => '#dc2626']]]],
            ['type' => 'text', 'text' => 'evil', 'marks' => [['type' => 'textColor', 'attrs' => ['color' => 'red" onclick="x']]]],
            ['type' => 'text', 'text' => 'hi', 'marks' => [['type' => 'highlight']]],
            ['type' => 'text', 'text' => '2', 'marks' => [['type' => 'subscript']]],
            ['type' => 'text', 'text' => '3', 'marks' => [['type' => 'superscript']]],
        ]],
        ['type' => 'heading', 'attrs' => ['level' => 3, 'textAlign' => 'x" onclick="y'], 'content' => [['type' => 'text', 'text' => 'T']]],
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'attrs' => ['colspan' => 2], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'H']]]]],
            ]],
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => '"x'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]]]],
            ]],
        ]],
        ['type' => 'details', 'content' => [
            ['type' => 'detailsSummary', 'content' => [['type' => 'text', 'text' => 'More']]],
            ['type' => 'detailsContent', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]]]],
        ]],
    ]];

    expect(RichContentRenderer::make($document)->toHtml())->toBe(
        '<p style="text-align: center"><span data-color="#dc2626" style="color: #dc2626">red</span>evil<mark>hi</mark><sub>2</sub><sup>3</sup></p>'
        .'<h3>T</h3>'
        .'<table><tbody><tr><th colspan="2"><p>H</p></th></tr><tr><td><p>a</p></td></tr></tbody></table>'
        .'<details><summary>More</summary><div data-type="detailsContent"><p>Body</p></div></details>',
    );
});

it('renders the new markup from HTML content and sanitises it', function (): void {
    $html = '<p style="text-align: end"><span data-color="#2563eb" style="color: #2563eb">blue</span><mark>m</mark></p>'
        .'<details><summary>S</summary><div data-type="detailsContent"><p>x</p></div></details><img src="x" onerror="alert(1)">';

    expect(RichContentRenderer::make($html)->toHtml())->toBe(
        '<p style="text-align: end"><span data-color="#2563eb" style="color: #2563eb">blue</span><mark>m</mark></p>'
        .'<details><summary>S</summary><div data-type="detailsContent"><p>x</p></div></details><img src="x" />',
    );
});

// ---------------------------------------------------------------- Schema

it('serialises the text colour palette and attachment limits', function (): void {
    $props = schema([
        RichEditor::make('body')
            ->toolbarButtons(['bold', 'textColor', 'highlight', 'alignCenter', 'table', 'details', 'clearFormatting'])
            ->textColors(['#dc2626' => 'Red', 'url(x)' => 'Evil', 'rgb(0, 0, 255)' => 'Blue'])
            ->customTextColors()
            ->fileAttachmentsAcceptedFileTypes(['image/png'])
            ->fileAttachmentsMaxSize(fn (): int => 512),
    ])->fill()->toSchema()[0];

    expect($props['textColors'])->toBe([['value' => '#dc2626', 'label' => 'Red'], ['value' => 'rgb(0, 0, 255)', 'label' => 'Blue']])
        ->and($props['customTextColors'])->toBeTrue()
        ->and($props['fileAttachments']['acceptedFileTypes'])->toBe(['image/png'])
        ->and($props['fileAttachments']['maxSize'])->toBe(512)
        ->and($props['toolbarButtons'])->toContain('table', 'details', 'clearFormatting');
});

it('defaults the palette and leaves it out without a text colour button', function (): void {
    $without = schema([RichEditor::make('body')])->fill()->toSchema()[0];
    $with = schema([RichEditor::make('body')->toolbarButtons(['textColor'])])->fill()->toSchema()[0];

    expect($without['textColors'] ?? null)->toBeNull()
        ->and($without['fileAttachments']['acceptedFileTypes'])->toBe(['image/png', 'image/jpeg', 'image/gif', 'image/webp'])
        ->and($without['fileAttachments']['maxSize'])->toBe(12288)
        ->and($with['textColors'])->toHaveCount(10)
        ->and($with['textColors'][0]['value'])->toBe('#dc2626')
        ->and($with['customTextColors'] ?? null)->toBeNull();
});

it('keeps the markdown editor table button and renders markdown tables', function (): void {
    $props = schema([MarkdownEditor::make('body')])->fill(['body' => "| A | B |\n| --- | --- |\n| 1 | 2 |"])->toSchema()[0];

    expect($props['toolbarButtons'])->toContain('table')
        ->and($props['html'])->toContain('<table>')->toContain('<th>A</th>')->toContain('<td>2</td>');
});

// ---------------------------------------------------------------- Attachment validation

it('rejects attachments of a type the editor does not accept', function (): void {
    $user = toolbarUser();
    $this->actingAs($user);
    $gif = toolbarUpload(UploadedFile::fake()->image('anim.gif', 10, 10));

    toolbarActAs($user);
    $form = schema([RichEditor::make('body')->fileAttachmentsAcceptedFileTypes(['image/png', 'image/jpeg'])])->fill();
    $form->rawState(['body' => '<p>Hi</p><img src="'.$gif.'">']);

    expect(fn () => $form->validate())->toThrow(ValidationException::class)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects attachments larger than the maximum size', function (): void {
    $user = toolbarUser();
    $this->actingAs($user);
    $big = toolbarUpload(UploadedFile::fake()->image('big.png', 10, 10)->size(300));

    toolbarActAs($user);
    $form = schema([MarkdownEditor::make('body')->fileAttachmentsMaxSize(100)])->fill();
    $form->rawState(['body' => "![big]({$big})"]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);
});

it('accepts attachments matching wildcard types and the size limit, also in JSON documents', function (): void {
    $user = toolbarUser();
    $this->actingAs($user);
    $png = toolbarUpload(UploadedFile::fake()->image('photo.png', 10, 10));

    toolbarActAs($user);
    $form = schema([RichEditor::make('body')->json()->fileAttachmentsAcceptedFileTypes(['image/*'])->fileAttachmentsMaxSize(1024)])->fill();
    $form->rawState(['body' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $png]]]]]);

    $body = $form->validate()['body'];

    expect(json_encode($body))->not->toContain('tmp:')
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('still drops attachments that belong to someone else without an error', function (): void {
    $this->actingAs(toolbarUser());
    $key = toolbarUpload(UploadedFile::fake()->image('photo.png', 10, 10));

    toolbarActAs(toolbarUser('other-toolbar@example.com'));
    $form = schema([RichEditor::make('body')->fileAttachmentsAcceptedFileTypes(['image/jpeg'])])->fill();
    $form->rawState(['body' => '<p>Text</p><img src="'.$key.'">']);

    expect($form->validate()['body'])->toBe('<p>Text</p>');
});
