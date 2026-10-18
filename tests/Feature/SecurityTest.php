<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\ListPosts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class SecurityTestPostPolicy
{
    public static bool $viewAny = true;

    public static bool $update = true;

    public function viewAny(User $user): bool
    {
        return self::$viewAny;
    }

    public function update(User $user, Post $post): bool
    {
        return self::$update;
    }
}

beforeEach(function (): void {
    SecurityTestPostPolicy::$viewAny = true;
    SecurityTestPostPolicy::$update = true;
});

function securityUser(string $email = 'sec@example.com'): User
{
    return User::query()->create(['name' => 'Sec', 'email' => $email, 'password' => 'password']);
}

// ---------------------------------------------------------------- Form state

it('does not save or validate fields inside a hidden section', function (): void {
    $form = schema([
        TextInput::make('name'),
        Section::make('Admin')->hidden()->schema([Toggle::make('is_admin'), TextInput::make('note')->required()]),
    ])->fill();

    $form->applyClientState(['name' => 'Ada', 'is_admin' => true]);

    expect($form->validate())->toBe(['name' => 'Ada']);
});

it('ignores posted values for disabled fields unless they are dehydrated explicitly', function (): void {
    $form = schema([
        TextInput::make('name'),
        TextInput::make('role')->disabled()->required(),
        TextInput::make('code')->disabled()->dehydrated(),
    ])->fill(['role' => 'member', 'code' => 'A1']);

    $form->applyClientState(['name' => 'Ada', 'role' => 'admin', 'code' => 'B2']);

    expect($form->validate())->toBe(['name' => 'Ada', 'code' => 'B2']);
});

it('keeps hidden field values on the server', function (): void {
    $form = schema([
        TextInput::make('name'),
        TextInput::make('secret')->hidden(),
        Section::make('Internal')->visible(false)->schema([TextInput::make('internal_note')]),
    ])->fill(['name' => 'Ada', 'secret' => 's3cret', 'internal_note' => 'private']);

    expect($form->toPayload()['state'])->toBe(['name' => 'Ada']);

    $form->applyClientState(['name' => 'Grace', 'secret' => 'forged', 'internal_note' => 'forged']);

    expect($form->getRawState())->toBe(['name' => 'Grace', 'secret' => 's3cret', 'internal_note' => 'private']);
});

// ---------------------------------------------------------------- Escaping

it('escapes plain strings that renderers insert as HTML', function (): void {
    $payload = '<img src=x onerror=alert(1)>';

    expect(Notification::make()->title('Hi')->body($payload)->getBody())->toBe(e($payload))
        ->and(Notification::make()->body(new HtmlString('<b>ok</b>'))->getBody())->toBe('<b>ok</b>')
        ->and(Action::make('x')->modalDescription($payload)->getModalDescription())->toBe(e($payload))
        ->and(TextInput::make('x')->helperText($payload)->getHelperText())->toBe(e($payload))
        ->and(Html::markdown('**hi** <script>alert(1)</script> [x](javascript:alert(1))'))
        ->not->toContain('<script>')
        ->not->toContain('javascript:');
});

it('does not escape stored notifications twice', function (): void {
    $array = Notification::make()->title('Hi')->body('Tom & Jerry')->toArray();

    expect(Notification::fromArray($array)->getBody())->toBe('Tom &amp; Jerry');
});

// ---------------------------------------------------------------- Actions

it('treats actions inside a hidden group as hidden', function (): void {
    $visible = Action::make('promote');
    ActionGroup::make([ActionGroup::make([$visible])]);

    $inHiddenOuter = Action::make('promote');
    ActionGroup::make([ActionGroup::make([$inHiddenOuter])])->hidden();

    $inHiddenInner = Action::make('promote');
    ActionGroup::make([ActionGroup::make([$inHiddenInner])->hidden()]);

    expect($visible->isHidden())->toBeFalse()
        ->and($inHiddenOuter->isHidden())->toBeTrue()
        ->and($inHiddenInner->isHidden())->toBeTrue();
});

// ---------------------------------------------------------------- Policies

it('denies list pages without the viewAny ability', function (): void {
    Gate::policy(Post::class, SecurityTestPostPolicy::class);
    SecurityTestPostPolicy::$viewAny = false;

    inertiaGet($this->actingAs(securityUser()), '/admin/posts')->assertForbidden();
});

it('checks the update policy for inline cell edits', function (): void {
    Gate::policy(Post::class, SecurityTestPostPolicy::class);
    $user = securityUser();
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['token'];

    SecurityTestPostPolicy::$update = false;

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => $post->id, 'column' => 'is_published', 'state' => true])
        ->assertForbidden();

    expect($post->fresh()->is_published)->toBeFalsy();
});

it('rejects cell edits for unknown columns', function (): void {
    $user = securityUser();
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['token'];

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => $post->id, 'column' => 'author_id', 'state' => 99])
        ->assertNotFound();
});

// ---------------------------------------------------------------- Tokens

it('binds context tokens to the user they were issued to', function (): void {
    $token = inertiaProps(inertiaGet($this->actingAs(securityUser()), '/admin/posts'))['page']['token'];

    $this->actingAs(securityUser('other@example.com'))
        ->postJson('/admin/_alfakit/actions', ['token' => $token, 'action' => 'create'])
        ->assertForbidden();
});

it('expires old context tokens', function (): void {
    $user = securityUser();
    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    $token = (new SchemaContext('admin', ListPosts::class, issuedAt: time() - 2 * 86400))->toToken();

    $this->postJson('/admin/_alfakit/actions', ['token' => $token, 'action' => 'create'])->assertStatus(419);
});

// ---------------------------------------------------------------- Profile

it('requires the current password to change the email address', function (): void {
    $user = securityUser();
    $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/profile'))['page']['token'];

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $token, 'action' => 'save', 'data' => ['name' => 'Sec', 'email' => 'new@example.com']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $token, 'action' => 'save', 'data' => ['name' => 'Sec', 'email' => 'new@example.com', 'current_password' => 'password']])
        ->assertRedirect();

    expect($user->fresh()->email)->toBe('new@example.com');
});
