<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Http\Middleware\PreserveFlashData;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Inertia\Support\SessionKey;

function createPostAndRedirect(AlfaSix\Alfakit\Tests\TestCase $test): void
{
    $user = admin();
    $props = inertiaProps(inertiaGet($test->actingAs($user), '/admin/posts/create'));

    inertiaPost($test, '/admin/_alfakit/actions', [
        'token' => $props['page']['token'],
        'action' => 'create',
        'data' => ['title' => 'Flash post', 'slug' => 'flash-post', 'author_id' => $user->id, 'is_published' => true],
    ])->assertRedirect('http://localhost/admin/posts/1/edit');
}

/**
 * @return list<mixed>
 */
function flashedTitles(Illuminate\Testing\TestResponse $response): array
{
    return collect((array) $response->json('flash.'.Notification::FLASH_KEY))->pluck('title')->all();
}

it('keeps the flashed toast for the next page when internal requests arrive first', function (): void {
    createPostAndRedirect($this);

    // Background requests of the page (database notifications, global search, select options)
    // land between the redirect and the page it leads to.
    $this->getJson('/admin/_alfakit/notifications')->assertOk();
    $this->getJson('/admin/_alfakit/global-search?search=x')->assertOk();
    $this->getJson('/admin/_alfakit/notifications')->assertOk();

    $page = inertiaGet($this, '/admin/posts/1/edit')->assertOk();

    expect(flashedTitles($page))->toBe(['Created']);

    // Shown once: the following page does not repeat it.
    expect(flashedTitles(inertiaGet($this, '/admin/posts/1/edit')))->toBe([]);
});

it('keeps the pending toast out of the JSON of an internal endpoint that sends its own', function (): void {
    createPostAndRedirect($this);

    // A notification sent by an internal endpoint is still returned in its JSON
    // (see the wizard step test in GuestAccessAndPageHooksTest), without the pending one.
    $this->getJson('/admin/_alfakit/notifications')->assertOk()->assertJsonMissing(['title' => 'Created']);

    expect(flashedTitles(inertiaGet($this, '/admin/posts/1/edit')))->toBe(['Created']);
});

it('keeps flash data pending when a JSON request of a plugin endpoint arrives first', function (): void {
    $session = new Store('test', new ArraySessionHandler(120));
    $session->start();
    $session->flash(SessionKey::FLASH_DATA, [Notification::FLASH_KEY => [['title' => 'Created']]]);
    $session->flash('status', 'saved');
    // End of the redirecting request: the flash data now waits for the next one.
    $session->save();
    $session->start();

    $request = Request::create('/admin/calendar/events', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $request->setLaravelSession($session);

    (new PreserveFlashData)->handle($request, function () use ($session) {
        // The endpoint doesn't see (or consume) the pending flash data, and flashes its own.
        expect($session->get('status'))->toBeNull();
        $session->flash('status', 'own');
        $session->flash(SessionKey::FLASH_DATA, [Notification::FLASH_KEY => [['title' => 'Background']]]);

        return response()->json([]);
    });

    $session->save();
    $session->start();

    expect($session->get('status'))->toBe('own')
        ->and(collect($session->get(SessionKey::FLASH_DATA)[Notification::FLASH_KEY])->pluck('title')->all())->toBe(['Created', 'Background']);
});

it('keeps validation errors pending when an asset request that started before the redirect ends after it', function (): void {
    $handler = new ArraySessionHandler(120);
    $assets = new Store('test', $handler, str_repeat('a', 40));
    $assets->start();

    // A PWA icon (or another subresource) request starts while the page is idle…
    $request = Request::create('/admin/pwa/icon-192.png', server: ['HTTP_SEC_FETCH_DEST' => 'image']);
    $request->setLaravelSession($assets);

    (new PreserveFlashData)->handle($request, function () use ($handler) {
        // …meanwhile the action POST fails validation and flashes the errors.
        $action = new Store('test', $handler, str_repeat('a', 40));
        $action->start();
        $action->flash('errors', ['title' => ['The title field is required.']]);
        $action->save();

        return response('icon');
    });

    $assets->save();

    // The icon request's save does not drop the errors for the page the POST redirects to.
    $page = new Store('test', $handler, str_repeat('a', 40));
    $page->start();

    expect($page->get('errors'))->toBe(['title' => ['The title field is required.']]);
});
