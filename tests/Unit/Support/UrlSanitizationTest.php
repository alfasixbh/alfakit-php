<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Url;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

/*
 * Security: URLs serialised into links only use http(s), mailto, tel or relative paths.
 */

it('allows safe schemes, relative paths and fragments', function (string $url): void {
    expect(Url::sanitize($url))->toBe($url);
})->with([
    'https://alfakit.dev/docs?q=a:b',
    'http://localhost:8000',
    'mailto:ada@example.com',
    'tel:+97312345678',
    '/admin/posts/1',
    'posts/1/edit',
    '?page=2',
    '#comments',
    'HTTPS://ALFAKIT.DEV',
]);

it('drops dangerous schemes, including obfuscated ones', function (string $url): void {
    expect(Url::sanitize($url))->toBeNull();
})->with([
    'javascript:alert(1)',
    'JavaScript:alert(1)',
    '  javascript:alert(1)',
    "java\tscript:alert(1)",
    "java\nscript:alert(1)",
    "\x01javascript:alert(1)",
    'javascript&colon;alert(1)',
    'java&#x09;script:alert(1)',
    '&#106;avascript:alert(1)',
    "\u{200B}javascript:alert(1)",
    'data:text/html;base64,PHNjcmlwdD4=',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
]);

it('returns null for empty values', function (): void {
    expect(Url::sanitize(null))->toBeNull()
        ->and(Url::sanitize(''))->toBeNull()
        ->and(Url::sanitize('   '))->toBeNull();
});

it('drops unsafe urls on columns, entries, actions, navigation, menu items, notification actions and stats', function (): void {
    $bad = 'javascript:alert(document.cookie)';

    $entry = Schema::make()->components([TextEntry::make('name')->state('Ada')->url($bad)])->toSchema()[0];

    expect(TextColumn::make('name')->url($bad)->toCell(['name' => 'Ada']))->not->toHaveKey('url')
        ->and(TextColumn::make('name')->url('/users/1')->toCell(['name' => 'Ada'])['url'])->toBe('/users/1')
        ->and($entry)->not->toHaveKey('url')
        ->and(Action::make('open')->url($bad)->getUrl())->toBeNull()
        ->and(Action::make('open')->url('https://alfakit.dev')->getUrl())->toBe('https://alfakit.dev')
        ->and(NavigationItem::make('Docs')->url($bad)->getUrl())->toBeNull()
        ->and(MenuItem::make()->label('Docs')->url($bad)->toSchema())->not->toHaveKey('url')
        ->and(NotificationAction::make('view')->url($bad)->toArray())->not->toHaveKey('url')
        ->and(Stat::make('Users', 10)->url($bad)->toSchema())->not->toHaveKey('url');
});

it('drops unsafe global search result URLs', function (): void {
    $result = new AlfaSix\Alfakit\GlobalSearch\GlobalSearchResult('Ada', 'javascript:alert(1)');

    expect($result->toArray()['url'])->toBeNull()
        ->and((new AlfaSix\Alfakit\GlobalSearch\GlobalSearchResult('Ada', '/admin/users/1'))->toArray()['url'])->toBe('/admin/users/1');
});

it('registers Str::sanitizeHtml() and Str::sanitizeUrl()', function (): void {
    expect(Illuminate\Support\Str::sanitizeUrl('javascript:alert(1)'))->toBeNull()
        ->and(Illuminate\Support\Str::sanitizeUrl('https://alfakit.dev'))->toBe('https://alfakit.dev')
        ->and(Illuminate\Support\Str::sanitizeHtml('<p>Hi<script>alert(1)</script></p>'))->toBe('<p>Hi</p>');
});
