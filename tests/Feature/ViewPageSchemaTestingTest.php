<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\EditParityPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages\ViewParityPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPanelProvider;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    ViewParityPost::$contentUsing = null;
    ParityPanelProvider::registerPanel();
    $this->user = User::query()->create(['name' => 'Pat', 'email' => 'pat'.uniqid().'@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
    $this->post = Post::query()->create(['title' => 'Shown', 'slug' => 'shown-'.uniqid(), 'author_id' => $this->user->id, 'status' => 'review']);
});

afterEach(function (): void {
    ViewParityPost::$contentUsing = null;
});

it('asserts a view page infolist state without naming the schema', function (): void {
    PageTester::make(ViewParityPost::class, ['record' => $this->post], 'parity')
        ->assertSchemaStateSet(['title' => 'Shown', 'status' => 'review'])
        ->assertSchemaStateSet(fn (array $state): array => ['title' => $state['title']])
        ->assertSchemaComponentExists('title')
        ->assertSchemaComponentDoesNotExist('content');

    expect(fn () => PageTester::make(ViewParityPost::class, ['record' => $this->post], 'parity')->assertSchemaStateSet(['status' => 'draft']))
        ->toThrow(ExpectationFailedException::class);
});

it('keeps the form as the default schema on pages with a form', function (): void {
    PageTester::make(EditParityPost::class, ['record' => $this->post], 'parity')
        ->assertSchemaStateSet(['title' => 'Shown'])
        ->assertSchemaComponentExists('content-section');
});

it('finds layout components by key in page content', function (): void {
    ViewParityPost::$contentUsing = fn (Schema $schema): Schema => $schema->components([
        Tabs::make('Details')->key('detail-tabs')->tabs([
            Tab::make('Notes')->key('notes-tab')->schema([
                Section::make('Inner')->key('inner-section')->schema([Text::make('Hello')]),
            ]),
        ]),
    ]);

    PageTester::make(ViewParityPost::class, ['record' => $this->post], 'parity')
        ->assertSchemaComponentExists('detail-tabs', 'content')
        ->assertSchemaComponentExists('notes-tab', 'content')
        ->assertSchemaComponentExists('inner-section', 'content', fn (array $section): bool => $section['heading'] === 'Inner')
        ->assertSchemaComponentDoesNotExist('missing-section', 'content');

    expect(fn () => PageTester::make(ViewParityPost::class, ['record' => $this->post], 'parity')->assertSchemaComponentExists('missing-section', 'content'))
        ->toThrow(AssertionFailedError::class);
});
