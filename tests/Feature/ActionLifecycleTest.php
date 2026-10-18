<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tests\Fixtures\ActionLifecycle\LifecyclePage;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/*
 * Authorisation feedback on actions (`authorizationTooltip()`, `authorizationNotification()`,
 * `authorizationMessage()`) and the modal lifecycle (`mountUsing()`, `modalHidden()`,
 * `disabledForm()`, `modalSubmitAction()` / `modalCancelAction()` closures, unsaved-changes
 * alerts).
 */

beforeEach(function (): void {
    LifecyclePage::$actions = null;
    LifecyclePage::$runs = [];
});

function lifecyclePanel(?Closure $configure = null): Panel
{
    return Panels::register('lifecycle', function (Panel $panel) use ($configure): Panel {
        $panel->login()->pages([LifecyclePage::class]);

        return $configure === null ? $panel : $configure($panel);
    });
}

/**
 * @param  Closure(): array<Action>  $actions
 * @return array{token: string, actions: array<int, array<string, mixed>>}
 */
function lifecyclePage(mixed $test, Closure $actions, ?User $user = null): array
{
    LifecyclePage::$actions = $actions;
    $test->actingAs($user ?? User::query()->create(['name' => 'Lc', 'email' => 'lc'.uniqid().'@example.com', 'password' => 'password']));

    $props = inertiaProps(inertiaGet($test, '/lifecycle/lifecycle'));

    return ['token' => $props['page']['token'], 'actions' => $props['page']['headerActions'] ?? []];
}

function lifecycleModal(mixed $test, string $token, string $action): Illuminate\Testing\TestResponse
{
    return $test->getJson('/lifecycle/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => $action]));
}

function lifecycleRun(mixed $test, string $token, string $action, array $data = []): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, '/lifecycle/_alfakit/actions', ['token' => $token, 'action' => $action, 'data' => $data]);
}

// ---------------------------------------------------------------- Authorisation feedback

it('hides unauthorised actions by default', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('publish')->authorize(fn (): Response => Response::deny('Only editors can publish.'))->action(fn () => null),
    ]);

    expect(collect($page['actions'])->pluck('name')->all())->not->toContain('publish');
});

it('shows an unauthorised action disabled with the policy message as tooltip', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('publish')
            ->authorize(fn (): Response => Response::deny('Only editors can publish.'))
            ->authorizationTooltip()
            ->url('/somewhere')
            ->action(fn () => LifecyclePage::$runs[] = 'publish'),
    ]);

    $action = collect($page['actions'])->firstWhere('name', 'publish');

    expect($action['disabled'])->toBeTrue()
        ->and($action['tooltip'])->toBe('Only editors can publish.')
        // An unauthorised link never navigates.
        ->and($action)->not->toHaveKey('url');

    lifecycleModal($this, $page['token'], 'publish')->assertForbidden();

    // Refused either way (a link is never executed, an unauthorised action never runs).
    expect(lifecycleRun($this, $page['token'], 'publish')->status())->toBeIn([400, 403]);

    expect(LifecyclePage::$runs)->toBe([]);
});

it('keeps an action hidden when the policy has no message, unless authorizationMessage() is set', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('silent')->authorize(false)->authorizationTooltip()->action(fn () => null),
        Action::make('fallback')->authorize(false)->authorizationTooltip()->authorizationMessage('Ask an admin.')->action(fn () => null),
    ]);

    $names = collect($page['actions'])->pluck('name')->all();

    expect($names)->not->toContain('silent')
        ->and(collect($page['actions'])->firstWhere('name', 'fallback')['tooltip'])->toBe('Ask an admin.');
});

it('uses the Gate response message of a policy ability', function (): void {
    lifecyclePanel();
    Gate::define('lifecycle-publish', fn (User $user): Response => Response::deny('Publishing is closed today.'));

    $page = lifecyclePage($this, fn (): array => [
        Action::make('publish')->authorize('lifecycle-publish')->authorizationTooltip()->action(fn () => null),
    ]);

    expect(collect($page['actions'])->firstWhere('name', 'publish')['tooltip'])->toBe('Publishing is closed today.');
});

it('sends the policy message as a notification instead of running the action', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('archive')
            ->requiresConfirmation()
            ->authorize(fn (): Response => Response::deny('Archived posts are read-only.'))
            ->authorizationNotification()
            ->action(fn () => LifecyclePage::$runs[] = 'archive'),
    ]);

    $action = collect($page['actions'])->firstWhere('name', 'archive');

    // Clickable, and it posts straight to the server (no confirmation modal).
    expect($action)->not->toHaveKey('disabled')
        ->and($action)->not->toHaveKey('hasModal')
        ->and($action['hasAction'])->toBeTrue();

    lifecycleModal($this, $page['token'], 'archive')->assertForbidden();
    lifecycleRun($this, $page['token'], 'archive')->assertRedirect();

    expect(LifecyclePage::$runs)->toBe([])
        ->and(Inertia::getFlashed()['alfakitAction']['status'])->toBe('halted')
        ->and(Inertia::getFlashed()['alfakitNotifications'][0]['title'])->toBe('Archived posts are read-only.')
        ->and(Inertia::getFlashed()['alfakitNotifications'][0]['status'])->toBe('danger');
});

it('authorises actions per record with the policy response', function (): void {
    lifecyclePanel();
    $locked = Post::query()->create(['title' => 'Locked', 'slug' => 'locked-'.uniqid(), 'status' => 'locked']);
    $open = Post::query()->create(['title' => 'Open', 'slug' => 'open-'.uniqid(), 'status' => 'draft']);

    $action = Action::make('edit')
        ->authorize(fn (Post $record): Response => $record->status === 'locked' ? Response::deny('This post is locked.') : Response::allow())
        ->authorizationTooltip();

    expect($action->record($locked)->toSchema()['tooltip'])->toBe('This post is locked.')
        ->and($action->record($open)->toSchema())->not->toHaveKey('tooltip')
        ->and($action->record($open)->isDisabled())->toBeFalse();
});

it('hides the children of an unauthorised but visible action', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('parent')
            ->authorize(fn (): Response => Response::deny('Nope.'))
            ->authorizationTooltip()
            ->requiresConfirmation()
            ->extraModalFooterActions([Action::make('child')->action(fn () => LifecyclePage::$runs[] = 'child')])
            ->action(fn () => null),
    ]);

    lifecycleRun($this, $page['token'], 'parent>child')->assertForbidden();

    expect(LifecyclePage::$runs)->toBe([]);
});

// ---------------------------------------------------------------- Modal lifecycle

it('runs mountUsing() when the modal opens instead of the default fill', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('draft')
            ->schema([TextInput::make('title')->default('Default')])
            ->mountUsing(function (Schema $form, array $arguments): void {
                LifecyclePage::$runs[] = 'mounted';
                $form->fill(['title' => 'From mountUsing']);
            })
            ->action(fn (array $data) => LifecyclePage::$runs[] = $data),
    ]);

    $modal = lifecycleModal($this, $page['token'], 'draft')->assertOk()->json();

    expect($modal['form']['state']['title'])->toBe('From mountUsing')
        ->and(LifecyclePage::$runs)->toBe(['mounted']);

    // Submitting doesn't mount again.
    lifecycleRun($this, $page['token'], 'draft', ['title' => 'Typed'])->assertRedirect();

    expect(LifecyclePage::$runs)->toBe(['mounted', ['title' => 'Typed']]);
});

it('does not open the modal when mountUsing() halts, and returns its notifications', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('limited')
            ->requiresConfirmation()
            ->mountUsing(function (Action $action): void {
                AlfaSix\Alfakit\Notifications\Notification::make()->warning()->title('Slow down')->send();
                $action->halt();
            })
            ->action(fn () => null),
    ]);

    $response = lifecycleModal($this, $page['token'], 'limited')->assertOk()->json();

    expect($response['cancelled'])->toBeTrue()
        ->and($response['notifications'][0]['title'])->toBe('Slow down');
});

it('runs mountUsing() without a form', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('confirm')
            ->requiresConfirmation()
            ->mountUsing(fn (?Schema $form) => LifecyclePage::$runs[] = $form === null ? 'no form' : 'form')
            ->action(fn () => null),
    ]);

    lifecycleModal($this, $page['token'], 'confirm')->assertOk();

    expect(LifecyclePage::$runs)->toBe(['no form']);
});

it('skips the modal with modalHidden() and runs with the default form state', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('quick')
            ->requiresConfirmation()
            ->schema([TextInput::make('reason')->default('auto')->required()])
            ->modalHidden()
            ->action(fn (array $data) => LifecyclePage::$runs[] = $data),
    ]);

    $action = collect($page['actions'])->firstWhere('name', 'quick');

    expect($action)->not->toHaveKey('hasModal');

    // Client data is ignored: no modal was shown.
    lifecycleRun($this, $page['token'], 'quick', ['reason' => 'injected'])->assertRedirect();

    expect(LifecyclePage::$runs)->toBe([['reason' => 'auto']]);
});

it('keeps the modal for password confirmation even when modalHidden()', function (): void {
    $action = Action::make('secure')->requiresPasswordConfirmation()->modalHidden()->action(fn () => null);

    expect($action->hasModal())->toBeTrue();
});

it('disables every field with disabledForm() and turns off the unsaved-changes alert', function (): void {
    lifecyclePanel(fn (Panel $panel): Panel => $panel->unsavedChangesAlerts());

    $page = lifecyclePage($this, fn (): array => [
        Action::make('review')
            ->schema([TextInput::make('title')->default('Fixed')])
            ->disabledForm()
            ->action(fn (array $data) => LifecyclePage::$runs[] = $data),
        Action::make('editable')
            ->schema([TextInput::make('title')])
            ->action(fn () => null),
        Action::make('noAlert')
            ->schema([TextInput::make('title')])
            ->unsavedChangesAlert(false)
            ->action(fn () => null),
    ]);

    $review = lifecycleModal($this, $page['token'], 'review')->assertOk()->json();
    $field = collect($review['form']['components'])->firstWhere('name', 'title');

    expect($field['disabled'])->toBeTrue()
        ->and($review)->not->toHaveKey('unsavedChangesAlert')
        ->and(lifecycleModal($this, $page['token'], 'editable')->json('unsavedChangesAlert'))->toBeTrue()
        ->and(lifecycleModal($this, $page['token'], 'noAlert')->json())->not->toHaveKey('unsavedChangesAlert');

    // The browser can't change a disabled field.
    lifecycleRun($this, $page['token'], 'review', ['title' => 'Changed'])->assertRedirect();

    expect(LifecyclePage::$runs[0]['title'] ?? null)->not->toBe('Changed');
});

it('enables the unsaved-changes alert per action without the panel option', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('plain')->schema([TextInput::make('title')])->action(fn () => null),
        Action::make('guarded')->schema([TextInput::make('title')])->unsavedChangesAlert()->action(fn () => null),
    ]);

    expect(lifecycleModal($this, $page['token'], 'plain')->json())->not->toHaveKey('unsavedChangesAlert')
        ->and(lifecycleModal($this, $page['token'], 'guarded')->json('unsavedChangesAlert'))->toBeTrue();
});

it('customises the modal buttons with modalSubmitAction() and modalCancelAction() closures', function (): void {
    lifecyclePanel();

    $page = lifecyclePage($this, fn (): array => [
        Action::make('send')
            ->requiresConfirmation()
            ->modalSubmitAction(fn (Action $action): Action => $action->label('Send now')->color(Color::Danger)->icon('send'))
            ->modalCancelAction(fn (Action $action): Action => $action->label('Not yet'))
            ->action(fn () => null),
        Action::make('help')
            ->modalContent([])
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (Action $action): Action => $action->hidden())
            ->action(fn () => null),
        Action::make('legacy')
            ->requiresConfirmation()
            ->modalSubmitAction(fn (): bool => false)
            ->action(fn () => null),
    ]);

    $send = lifecycleModal($this, $page['token'], 'send')->json();
    $help = lifecycleModal($this, $page['token'], 'help')->json();
    $legacy = lifecycleModal($this, $page['token'], 'legacy')->json();

    expect($send['submitLabel'])->toBe('Send now')
        ->and($send['submitColor'])->toBe('danger')
        ->and($send['submitIcon'])->toBe('send')
        ->and($send['cancelLabel'])->toBe('Not yet')
        ->and($help)->not->toHaveKey('submitLabel')
        ->and($help)->not->toHaveKey('cancelLabel')
        ->and($legacy)->not->toHaveKey('submitLabel')
        ->and($legacy['cancelLabel'])->toBe(__('alfakit::actions.modal.cancel'));
});

it('keeps ViewAction forms read-only through disabledForm()', function (): void {
    $action = ViewAction::make()->schema([TextInput::make('title')]);

    expect($action->isFormDisabled())->toBeTrue()
        ->and($action->hasUnsavedChangesAlert())->toBeFalse();
});

it('posts to the URL of a postToUrl() action', function (): void {
    $schema = Action::make('lock')->url('/lock')->postToUrl()->toSchema();

    expect($schema['postToUrl'])->toBeTrue()
        ->and($schema['hasAction'])->toBeTrue()
        ->and($schema['url'])->toBe('/lock');
});
