<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

it('escapes plain-string hints and keeps Htmlable hints', function (): void {
    expect(TextInput::make('a')->hint('<b>x</b> & y')->toSchema()['hint'])->toBe('&lt;b&gt;x&lt;/b&gt; &amp; y')
        ->and(TextInput::make('a')->hint(new HtmlString('<b>x</b>'))->toSchema()['hint'])->toBe('<b>x</b>')
        ->and(TextEntry::make('a')->hint('<i>x</i>')->toSchema()['hint'])->toBe('&lt;i&gt;x&lt;/i&gt;')
        ->and(TextEntry::make('a')->hint(new HtmlString('<i>x</i>'))->toSchema()['hint'])->toBe('<i>x</i>');
});

it('sends repeater item columns, defaulting to two from lg', function (): void {
    $form = schema([
        Repeater::make('items')->schema([TextInput::make('sku')]),
        Repeater::make('lines')->schema([TextInput::make('sku')])->columns(3),
    ]);
    $form->fill(['items' => [], 'lines' => []]);

    [$items, $lines] = $form->toSchema();

    expect($items['columns'])->toBe(['default' => 1, 'lg' => 2])
        ->and($lines['columns'])->toBe(['default' => 1, 'lg' => 3])
        ->and(RepeatableEntry::make('comments')->schema([TextEntry::make('body')])->columns(4)->toSchema()['columns'])
        ->toBe(['default' => 1, 'lg' => 4]);
});

it('sends the Markdown state rendered as escaped HTML for the editor to load', function (): void {
    $form = schema([MarkdownEditor::make('body')]);
    $form->fill(['body' => "**Hi** <script>x</script>\n\n- one"]);

    $html = $form->toSchema()[0]['html'];

    expect($html)->toContain('<strong>Hi</strong>')
        ->toContain('<li>one</li>')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
});

it('shows date-time bounds in the display timezone', function (): void {
    config(['app.timezone' => 'UTC']);

    $schema = DateTimePicker::make('starts_at')
        ->timezone('Asia/Bahrain')
        ->minDate(Carbon::parse('2026-01-01 09:00:00', 'UTC'))
        ->toSchema();

    expect($schema['minDate'])->toBe('2026-01-01 12:00');
});
