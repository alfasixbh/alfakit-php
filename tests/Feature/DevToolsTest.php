<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\DevTools\EditorLink;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\EditPost;

/*
 * The schema inspector (`Panel::devTools()`): the `alfakitDevTools` prop only exists while
 * `app.debug` is on and the panel enables it; it describes the context token without
 * secrets and links the page, resource and model classes to the configured editor.
 */

beforeEach(function (): void {
    $this->actingAs(admin());
    $this->post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => auth()->id()]);
});

afterEach(function (): void {
    alfakit()->getPanel('admin')->devTools(fn (): ?bool => null);
    config(['app.editor' => null]);
});

it('never sends dev tools data when app.debug is off', function (): void {
    config(['app.debug' => false]);
    alfakit()->getPanel('admin')->devTools();

    $response = inertiaGet($this, '/admin/posts');

    expect(inertiaProps($response))->not->toHaveKey(Page::DEV_TOOLS_PROP)
        ->and(alfakit()->getPanel('admin')->hasDevTools())->toBeFalse()
        ->and($response->headers->has('Server-Timing'))->toBeFalse();
});

it('is off outside local unless the panel opts in, and can be turned off', function (): void {
    config(['app.debug' => true]);

    expect(inertiaProps(inertiaGet($this, '/admin/posts')))->not->toHaveKey(Page::DEV_TOOLS_PROP);

    app()['env'] = 'local';

    try {
        expect(alfakit()->getPanel('admin')->hasDevTools())->toBeTrue();

        alfakit()->getPanel('admin')->devTools(false);

        expect(alfakit()->getPanel('admin')->hasDevTools())->toBeFalse();
    } finally {
        app()['env'] = 'testing';
    }
});

it('describes the context token and links the classes behind the page', function (): void {
    config(['app.debug' => true, 'app.editor' => 'vscode']);
    alfakit()->getPanel('admin')->devTools();

    $response = inertiaGet($this, "/admin/posts/{$this->post->getKey()}/edit");
    $props = inertiaProps($response);
    $tools = $props[Page::DEV_TOOLS_PROP];

    expect($response->headers->get('Server-Timing'))->toStartWith('alfakit;dur=')
        ->and($tools['context'])->toMatchArray([
            'panel' => 'admin',
            'page' => EditPost::class,
            'boundToUser' => true,
        ])
        ->and($tools['context'])->not->toHaveKey('user')
        ->and($tools['context']['parameters'])->toHaveKey('record')
        ->and($tools['context']['expiresAt'])->toBe($tools['context']['issuedAt'] + 1440 * 60)
        ->and($tools['editor'])->toBe('vscode')
        ->and(json_encode($tools))->not->toContain($props['page']['token']);

    $classes = collect($tools['classes'])->keyBy('role');

    expect($classes['page']['class'])->toBe(EditPost::class)
        ->and($classes['resource']['class'])->toBe(PostResource::class)
        ->and($classes['model']['class'])->toBe(Post::class)
        ->and($classes['page']['url'])->toStartWith('vscode://file/')
        ->and($classes['page']['line'])->toBeInt();
});

it('builds editor links from app.editor, ignition or nothing', function (): void {
    config(['app.editor' => null, 'ignition.editor' => null]);

    expect(EditorLink::forFile('/app/Post.php', 3))->toBeNull()
        ->and(EditorLink::editorName())->toBeNull();

    config(['ignition.editor' => 'phpstorm']);

    expect(EditorLink::forFile('/app/Post.php', 3))->toContain('phpstorm://')
        ->and(config('app.editor'))->toBeNull();

    config(['app.editor' => ['href' => 'myeditor://open?f={file}&l={line}']]);

    expect(EditorLink::forFile('/app/Post.php', 3))->toBe('myeditor://open?f=/app/Post.php&l=3');
});
