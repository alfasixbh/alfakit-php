<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Undo\UndoToken;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Undo\UndoPostResource;
use Illuminate\Support\Facades\Crypt;

/*
 * `Action::undoable()`: the "Undo" toast, its single-use token and the built-in undos.
 */

beforeEach(function (): void {
    UndoPostResource::$canDelete = true;
    UndoPostResource::$canUpdate = true;

    Panels::register('undo', fn (Panel $panel): Panel => $panel->resources([UndoPostResource::class]));
    $this->user = User::query()->create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
});

function undoPost(array $attributes = []): Post
{
    static $n = 0;
    $n++;

    return Post::query()->create(['title' => "Post {$n}", 'slug' => "post-{$n}", 'status' => 'draft', ...$attributes]);
}

function undoListToken(object $test): string
{
    return inertiaProps(inertiaGet($test, '/undo/undo-posts'))['page']['token'];
}

/**
 * Run a table action and return the undo descriptor of its toast.
 *
 * @param  array<string, mixed>  $payload
 * @return array{token: string, seconds: int, url: string}|null
 */
function runUndoable(object $test, array $payload, string $url = '/undo/_alfakit/actions'): ?array
{
    inertiaPost($test, $url, $payload)->assertRedirect();

    $undo = collect(Notification::getFlashed())->pluck('undo')->filter()->last();
    session()->forget(Inertia\Support\SessionKey::FLASH_DATA);

    return $undo;
}

it('restores a soft-deleted record from the toast, once', function (): void {
    $post = undoPost();
    $token = undoListToken($this);

    $undo = runUndoable($this, ['token' => $token, 'component' => 'table', 'action' => 'delete', 'record' => $post->id]);

    expect($post->fresh()->trashed())->toBeTrue()
        ->and($undo['seconds'])->toBe(8)
        ->and($undo['url'])->toBe('http://localhost/undo/_alfakit/actions/undo');

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->trashed())->toBeFalse();
    Notification::assertNotified(__('alfakit::actions.undo.notification'));

    // Single use: a second request finds nothing to undo.
    $post->delete();
    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->trashed())->toBeTrue();
    Notification::assertNotified(__('alfakit::actions.undo.expired'));
});

it('puts "Undo" on the success toast and keeps it up for the countdown', function (): void {
    $post = undoPost();

    inertiaPost($this, '/undo/_alfakit/actions', ['token' => undoListToken($this), 'component' => 'table', 'action' => 'delete', 'record' => $post->id])->assertRedirect();

    $toasts = Notification::getFlashed();

    expect($toasts)->toHaveCount(1)
        ->and($toasts[0]['title'])->toBe(__('alfakit::actions.delete.notification'))
        ->and($toasts[0]['undo']['token'])->toBeString()
        ->and($toasts[0]['duration'])->toBe(8000);
});

it('drops the delete confirmation of undoable delete actions', function (): void {
    $delete = collect(AlfaSix\Alfakit\Actions\DeleteAction::make()->undoable()->model(Post::class)->toModalSchema());

    expect($delete->get('requiresConfirmation'))->toBeNull()
        ->and(AlfaSix\Alfakit\Actions\DeleteAction::make()->undoable()->model(Post::class)->skipConfirmationWhenUndoable(false)->requiresConfirmationResolved())->toBeTrue()
        ->and(AlfaSix\Alfakit\Actions\DeleteAction::make()->model(Post::class)->requiresConfirmationResolved())->toBeTrue()
        // Without soft deletes there is nothing to restore: no undo, the modal stays.
        ->and(AlfaSix\Alfakit\Actions\DeleteAction::make()->undoable()->model(User::class)->isUndoable())->toBeFalse()
        ->and(AlfaSix\Alfakit\Actions\DeleteAction::make()->undoable()->model(User::class)->requiresConfirmationResolved())->toBeTrue();
});

it('restores only the records a bulk delete removed', function (): void {
    [$a, $b, $c] = [undoPost(), undoPost(), undoPost()];
    $c->delete();

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'delete', 'bulk' => true, 'records' => [$a->id, $b->id]]);

    expect($a->fresh()->trashed())->toBeTrue()->and($b->fresh()->trashed())->toBeTrue();

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($a->fresh()->trashed())->toBeFalse()
        ->and($b->fresh()->trashed())->toBeFalse()
        ->and($c->fresh()->trashed())->toBeTrue();
});

it('undoes an edit by restoring the previous attributes', function (): void {
    $post = undoPost(['title' => 'Before', 'status' => 'draft']);

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'edit', 'record' => $post->id, 'data' => ['title' => 'After', 'status' => 'draft']]);

    expect($post->fresh()->title)->toBe('After');

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->title)->toBe('Before');
});

it('refuses to undo an edit when the record changed since', function (): void {
    $post = undoPost(['title' => 'Before']);

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'edit', 'record' => $post->id, 'data' => ['title' => 'After', 'status' => 'draft']]);

    Post::query()->whereKey($post->id)->update(['title' => 'Someone else']);

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->title)->toBe('Someone else');
    Notification::assertNotified(__('alfakit::actions.undo.conflict'));
});

it('undoes the edit page save', function (): void {
    $post = undoPost(['title' => 'Before']);
    $token = inertiaProps(inertiaGet($this, "/undo/undo-posts/{$post->id}/edit"))['page']['token'];

    $undo = runUndoable($this, ['token' => $token, 'action' => 'save', 'data' => ['title' => 'After', 'status' => 'draft']]);

    expect($post->fresh()->title)->toBe('After')->and($undo)->not->toBeNull();

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->title)->toBe('Before')
        // The open edit form shows the restored values, not the undone ones.
        ->and(session(Inertia\Support\SessionKey::FLASH_DATA)['alfakitFormRefresh'] ?? null)->toMatchArray([
            'component' => 'form',
            'state' => ['title' => 'Before'],
        ]);
});

it('runs undoUsing() for custom actions with the data the handler kept', function (): void {
    $post = undoPost(['status' => 'review']);

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'publish', 'record' => $post->id]);

    expect($post->fresh()->status)->toBe('published')->and($undo['seconds'])->toBe(5);

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->status)->toBe('review');
});

it('offers no undo for actions that are not undoable', function (): void {
    $post = undoPost();

    expect(runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'plain', 'record' => $post->id]))->toBeNull();
});

it('checks the policy again when undoing', function (): void {
    $post = undoPost();

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'delete', 'record' => $post->id]);

    UndoPostResource::$canDelete = false;

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertForbidden();

    expect($post->fresh()->trashed())->toBeTrue();
});

it('binds the token to its user and refuses tampered or expired tokens', function (): void {
    $post = undoPost();

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'delete', 'record' => $post->id]);

    // Tampered.
    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token'].'x'])->assertStatus(400);
    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => Crypt::encryptString('{"n":"x"}')])->assertStatus(400);
    inertiaPost($this, '/undo/_alfakit/actions/undo', [])->assertStatus(400);

    // Another user.
    $this->actingAs(User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']));
    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertForbidden();

    // Expired (past the countdown and its grace period).
    $this->actingAs($this->user);
    $this->travel(8 + UndoToken::GRACE_SECONDS + 1)->seconds();
    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo['token']])->assertRedirect();

    expect($post->fresh()->trashed())->toBeTrue();
    Notification::assertNotified(__('alfakit::actions.undo.expired'));
});

it('binds the token to its tenant', function (): void {
    $post = undoPost();
    $token = undoListToken($this);
    $context = AlfaSix\Alfakit\Support\SchemaContext::fromToken($token);

    // Issued for a tenant while the page has none.
    $undo = UndoToken::issue(['strategy' => 'restore', 'context' => $token, 'model' => Post::class, 'keys' => [$post->id]], 8, $context->panel, (string) $this->user->id, '42');

    inertiaPost($this, '/undo/_alfakit/actions/undo', ['undo' => $undo])->assertForbidden();
});

it('binds the token to its panel', function (): void {
    $post = undoPost();
    Panels::register('other', fn (Panel $panel): Panel => $panel->resources([UndoPostResource::class]));

    $undo = runUndoable($this, ['token' => undoListToken($this), 'component' => 'table', 'action' => 'delete', 'record' => $post->id]);

    inertiaPost($this, '/other/_alfakit/actions/undo', ['undo' => $undo['token']])->assertForbidden();

    expect($post->fresh()->trashed())->toBeTrue();
});
