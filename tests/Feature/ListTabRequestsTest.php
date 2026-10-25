<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\Pages\ListArticles;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

/*
 * W-V1-11 / W-V1-14: the list's default tab is Published; a draft only exists on the Drafts
 * tab. Inline cell edits and the forms of a record action's modal resolve the record
 * through the page's `tableQuery`, like the modal request itself.
 */

beforeEach(function (): void {
    ArPanelProvider::registerPanel();
    ListArticles::$defaultTab = 'published';
    ArticleResource::$withTabRequests = true;
});

afterEach(function (): void {
    ListArticles::$defaultTab = null;
    ArticleResource::$withTabRequests = false;
});

function ltrDraftAndToken(mixed $test): array
{
    $user = User::query()->create(['name' => 'Lt', 'email' => 'lt'.uniqid().'@example.com', 'password' => 'password']);
    $draft = Post::query()->create(['title' => 'Draft', 'slug' => 'draft-'.uniqid(), 'is_published' => false]);
    $token = inertiaProps(inertiaGet($test->actingAs($user), '/ar/articles?activeTab=drafts'))['page']['token'];

    return [$draft, $token];
}

it('saves an inline cell edit of a record on a non-default list tab', function (): void {
    [$draft, $token] = ltrDraftAndToken($this);

    $this->postJson('/ar/_alfakit/table/cell', [
        'token' => $token,
        'component' => 'table',
        'record' => $draft->id,
        'column' => 'is_published',
        'state' => true,
        'tableQuery' => 'activeTab=drafts',
    ])->assertOk();

    expect($draft->fresh()->is_published)->toBeTruthy();
});

it('still refuses a cell edit of a record outside the default tab without the page query', function (): void {
    [$draft, $token] = ltrDraftAndToken($this);

    $this->postJson('/ar/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => $draft->id, 'column' => 'is_published', 'state' => true])
        ->assertNotFound();
});

it('validates a wizard step of a record action modal on a non-default list tab', function (): void {
    [$draft, $token] = ltrDraftAndToken($this);

    $this->postJson('/ar/_alfakit/schema/validate', [
        'token' => $token,
        'component' => 'action:table.onboard',
        'record' => $draft->id,
        'state' => ['email' => 'a@example.com', 'bio' => ''],
        'step' => 'account',
        'tableQuery' => 'activeTab=drafts',
    ])->assertNoContent();
});
