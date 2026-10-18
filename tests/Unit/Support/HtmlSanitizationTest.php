<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentCustomBlock;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Html as HtmlComponent;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/*
 * Security: HTML that the renderers insert with dangerouslySetInnerHTML / v-html / {@html}
 * is sanitised on the server unless it is markup written in code.
 */

it('removes scripts, event handlers and unsafe URLs', function (): void {
    $html = Html::sanitize(
        '<p onclick="steal()">Hi <strong>there</strong></p><script>alert(1)</script>'
        .'<img src="x" onerror="alert(1)"><a href="javascript:alert(1)">bad</a>'
        .'<a href=" JaVa&#x09;ScRiPt:alert(1)">tricky</a><iframe src="https://evil.test"></iframe>'
        .'<img src="data:text/html;base64,PHNjcmlwdD4=">',
    );

    expect($html)->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('javascript')
        ->not->toContain('ScRiPt')
        ->not->toContain('iframe')
        ->not->toContain('data:text')
        ->toContain('<strong>there</strong>');
});

it('keeps what the rich editor produces', function (): void {
    $editor = '<h2>Title</h2><p><strong>b</strong> <em>i</em> <u>u</u> <s>s</s> <code>c</code></p>'
        .'<blockquote><p>q</p></blockquote><ul><li>one</li></ul><ol start="3"><li>three</li></ol>'
        .'<pre><code class="language-php">echo 1;</code></pre><hr>'
        .'<p><a target="_blank" href="https://alfakit.dev/docs">docs</a> <a href="/admin/posts">rel</a> <a href="mailto:a@b.test">m</a></p>'
        .'<img src="https://cdn.test/a.png" alt="A"><img src="data:image/png;base64,iVBORw0KGgo=" alt="B">'
        .'<p><span data-type="mention" data-id="7" class="alfakit-mention fixed inset-0">@Ada</span></p>'
        .'<table><thead><tr><th colspan="2">h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table>';

    $html = Html::sanitize($editor);

    expect($html)->toContain('<h2>Title</h2>')
        ->toContain('<u>u</u>')
        ->toContain('<ol start="3">')
        ->toContain('<code class="language-php">')
        ->toContain('href="https://alfakit.dev/docs"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer nofollow"')
        ->toContain('href="/admin/posts"')
        ->toContain('href="mailto:a&#64;b.test"')
        ->toContain('src="https://cdn.test/a.png"')
        ->toContain('src="data:image/png;base64,iVBORw0KGgo&#61;"')
        ->toContain('data-type="mention"')
        ->toContain('class="alfakit-mention"')
        ->toContain('<th colspan="2">h</th>')
        ->not->toContain('inset-0');
});

it('sanitises html() state on text columns and entries', function (): void {
    $payload = '<p>Bio</p><img src=x onerror=alert(1)>';

    $cell = TextColumn::make('bio')->html()->toCell(['bio' => $payload]);
    $entry = Schema::make()->components([TextEntry::make('bio')->html()->state($payload)])->toSchema()[0];

    expect($cell['state'])->toContain('<p>Bio</p>')->not->toContain('onerror')
        ->and($entry['state'])->toContain('<p>Bio</p>')->not->toContain('onerror');
});

it('sanitises each item of a list rendered as HTML', function (): void {
    $cell = TextColumn::make('tags')->html()->toCell(['tags' => ['<b>a</b>', '<script>x</script>b']]);

    expect($cell['state'])->toBe(['<b>a</b>', 'b']);
});

it('trusts Htmlable state from code and can be turned off with sanitize: false', function (): void {
    $trusted = TextColumn::make('bio')->html()
        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<button onclick="go()">'.e($state).'</button>'))
        ->toCell(['bio' => 'Go']);
    $optOut = TextColumn::make('bio')->html(sanitize: false)->toCell(['bio' => '<span onclick="go()">x</span>']);

    expect($trusted['state'])->toBe('<button onclick="go()">Go</button>')
        ->and($optOut['state'])->toBe('<span onclick="go()">x</span>');
});

it('sanitises markdown output', function (): void {
    $cell = TextColumn::make('body')->markdown()->toCell(['body' => "[x](javascript:alert(1)) <script>alert(1)</script>\n\n![i](data:text/html,x)"]);

    expect($cell['state'])->not->toContain('javascript')->not->toContain('<script')->not->toContain('data:text');
});

it('sanitises string content of the Html component but trusts Htmlable', function (): void {
    $schema = Schema::make()->components([
        HtmlComponent::make('<p>ok</p><script>alert(1)</script>'),
        HtmlComponent::make(new HtmlString('<svg viewBox="0 0 1 1"></svg>')),
        HtmlComponent::make('<span onclick="x()">raw</span>')->sanitize(false),
        HtmlComponent::make(new HtmlString('<b onclick="x()">forced</b>'))->sanitize(),
    ])->toSchema();

    expect($schema[0]['content'])->toBe('<p>ok</p>')
        ->and($schema[1]['content'])->toBe('<svg viewBox="0 0 1 1"></svg>')
        ->and($schema[2]['content'])->toBe('<span onclick="x()">raw</span>')
        ->and($schema[3]['content'])->toBe('<b>forced</b>');
});

it('sanitises stored HTML in RichContentRenderer but keeps merge tags and custom blocks', function (): void {
    $block = new class extends RichContentCustomBlock
    {
        public static function getId(): string
        {
            return 'hero';
        }

        public static function toHtml(array $config, array $data): string
        {
            return '<section class="hero" style="color:red">'.e((string) ($config['title'] ?? '')).'</section>';
        }
    };

    $html = RichContentRenderer::make(
        '<p onmouseover="steal()">Hi <span data-type="mergeTag" data-id="name">{{ name }}</span></p>'
        .'<div data-type="customBlock" data-id="hero" data-config="{&quot;title&quot;:&quot;Big&quot;}"></div>'
        .'<a href="javascript:alert(1)">x</a><script>alert(1)</script>',
    )->mergeTags(['name' => '<Ada>'])->customBlocks([$block::class])->toHtml();

    expect($html)->toContain('<p>Hi &lt;Ada&gt;</p>')
        ->toContain('<section class="hero" style="color:red">Big</section>')
        ->not->toContain('onmouseover')
        ->not->toContain('javascript')
        ->not->toContain('<script');

    expect(RichContentRenderer::make('<i onclick="x()">t</i>')->sanitize(false)->toHtml())->toBe('<i onclick="x()">t</i>');
});

it('uses the panel html sanitizer config', function (): void {
    $panel = Panels::register('sanitize', fn ($panel) => $panel->htmlSanitizer(
        fn (HtmlSanitizerConfig $config): HtmlSanitizerConfig => $config->allowElement('iframe', ['src'])->allowElement('kbd', [])->dropElement('strong'),
    ));
    alfakit()->setCurrentPanel($panel);

    expect(Html::sanitize('<iframe src="https://www.youtube.com/embed/x"></iframe><strong>gone</strong>'))
        ->toBe('<iframe src="https://www.youtube.com/embed/x"></iframe>');
});
