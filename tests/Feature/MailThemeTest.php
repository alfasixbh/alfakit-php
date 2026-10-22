<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use AlfaSix\Alfakit\Auth\Notifications\NoticeOfEmailChangeRequest;
use AlfaSix\Alfakit\Auth\Notifications\ResetPassword;
use AlfaSix\Alfakit\Auth\Notifications\VerifyEmailChange;
use AlfaSix\Alfakit\Mail\Concerns\UsesAlfakitMailTheme;
use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\Contracts\MailTemplateRepository;
use AlfaSix\Alfakit\Mail\CoreMails;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\MailPreview;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Gate;

function mailPanel(?Closure $configure = null): Panel
{
    $panel = Panels::register('mailer', function (Panel $panel) use ($configure): Panel {
        $panel->login()->default()->brandName('Acme Mail')->colors(['primary' => '#e11d48'])->pages([Dashboard::class, MailPreview::class]);

        return $configure === null ? $panel : $configure($panel);
    });

    config()->set('alfakit.mail.panel', 'mailer');

    return $panel;
}

/**
 * A repository serving edited templates from an array: `[key][locale] => [subject, body]`.
 *
 * @param  array<string, array<string, array{subject: ?string, body: ?string}>>  $templates
 */
function editedTemplates(array $templates): void
{
    app()->instance(MailTemplateRepository::class, new class($templates) implements MailTemplateRepository
    {
        /** @param array<string, array<string, array{subject: ?string, body: ?string}>> $templates */
        public function __construct(private array $templates) {}

        public function find(string $key, string $locale): ?array
        {
            return $this->templates[$key][$locale] ?? null;
        }
    });
}

class ThemedOrderMail extends Mailable
{
    use UsesAlfakitMailTheme;
    use UsesEditableTemplate;

    public function __construct(public string $number = 'A-100') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->templateSubject('Order '.$this->number));
    }

    public function content(): Content
    {
        return $this->templateContent(new Content(markdown: 'alfakit::mail.template', with: ['alfakitBody' => '<p>Default body for '.e($this->number).'</p>']));
    }

    public function templateKey(): string
    {
        return 'tests.order';
    }

    public function templateData(): array
    {
        return ['number' => $this->number, 'customer' => '<b>Ada</b>', 'button' => EditableTemplates::button('https://example.com/orders/1', 'Track')];
    }
}

beforeEach(function (): void {
    MailTheme::flush();
    MailPreview::authorize(null);
});

afterEach(function (): void {
    MailTheme::flush();
    MailPreview::authorize(null);
    Mails::forget('tests.order');
});

it('renders notifications in the branded theme, left to right in English', function (): void {
    mailPanel();

    $html = (string) (new VerifyEmailAuthentication('428913', 10, 'Ada'))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('dir="ltr"')
        ->toContain('lang="en"')
        ->toContain('Acme Mail')
        ->toContain('#e11d48')
        ->toContain('428913')
        ->toContain('prefers-color-scheme: dark')
        ->toContain('role="presentation"')
        ->not->toContain('Laravel');
});

it('renders right to left in Arabic', function (): void {
    mailPanel();

    $html = MailTheme::withLocale('ar', fn (): string => (string) (new VerifyEmailAuthentication('428913', 10))->toMail(new AnonymousNotifiable)->render());

    expect($html)->toContain('dir="rtl"')->toContain('lang="ar"')->toContain('text-align: right');
});

it('renders every registered core mail with the brand, in both directions', function (string $key): void {
    mailPanel();
    $definition = Mails::get($key);

    expect($definition)->not->toBeNull()->and($definition->hasPreview())->toBeTrue();

    $ltr = MailPreview::renderMail($definition, 'en');
    $rtl = MailPreview::renderMail($definition, 'ar');

    expect($ltr['html'])->toContain('Acme Mail')->toContain('dir="ltr"')
        ->and($ltr['subject'])->not->toBe('')
        ->and($rtl['html'])->toContain('Acme Mail')->toContain('dir="rtl"');
})->with([
    VerifyEmailAuthentication::TEMPLATE,
    VerifyEmailChange::TEMPLATE,
    NoticeOfEmailChangeRequest::TEMPLATE,
    ResetPassword::TEMPLATE,
    CoreMails::EMAIL_VERIFICATION,
    CoreMails::USERS_INVITATION,
]);

it('sends the themed HTML and text through the mail channel', function (): void {
    mailPanel();
    $message = (new VerifyEmailChange('https://example.com/verify', 'old@example.com'))->toMail(new AnonymousNotifiable);

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and($message->view)->toBeArray();

    $html = value($message->view['html'], []);
    $text = value($message->view['text'], []);

    expect((string) $html)->toContain('Acme Mail')->toContain('https://example.com/verify')
        ->and((string) $text)->toContain('Acme Mail')->toContain('https://example.com/verify')->not->toContain('<table');
});

it('themes mailables that use the trait', function (): void {
    mailPanel();

    $html = (new ThemedOrderMail)->render();

    expect($html)->toContain('Acme Mail')->toContain('Default body for A-100')->toContain('dir="ltr"');
});

it('forces the dark styles for previews', function (): void {
    mailPanel();

    $html = MailTheme::withDarkMode(fn (): string => (string) (new VerifyEmailAuthentication('1', 1))->toMail(new AnonymousNotifiable)->render());

    expect($html)->toContain('content="dark"')->toContain('#0b0f19')->not->toContain('prefers-color-scheme: dark');
});

it('keeps the application theme when the AlfaKit theme is off', function (): void {
    mailPanel();
    config()->set('alfakit.mail.theme', false);

    $html = (string) (new VerifyEmailAuthentication('428913', 10))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('428913')->not->toContain('prefers-color-scheme: dark');
});

it('ends with the opt-in Powered by line', function (): void {
    mailPanel();

    $without = (string) (new VerifyEmailAuthentication('1', 1))->toMail(new AnonymousNotifiable)->render();
    config()->set('alfakit.credit.emails', true);
    $with = (string) (new VerifyEmailAuthentication('1', 1))->toMail(new AnonymousNotifiable)->render();

    expect($without)->not->toContain('https://alfakit.dev')->and($with)->toContain('https://alfakit.dev');
});

it('uses the configured logo and skips SVG panel logos', function (): void {
    mailPanel(fn (Panel $panel): Panel => $panel->brandLogo('/images/logo.svg'));

    expect(MailTheme::brand()->logo)->toBeNull();

    config()->set('alfakit.mail.logo', 'https://cdn.example.com/logo.png');
    config()->set('alfakit.mail.dark_logo', '/images/logo-dark.png');

    $html = (string) (new VerifyEmailAuthentication('1', 1))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('src="https://cdn.example.com/logo.png"')
        ->toContain('src="http://localhost/images/logo-dark.png"')
        ->toContain('alt="Acme Mail"');
});

it('themes Laravel\'s email verification mail', function (): void {
    mailPanel();

    expect(VerifyEmail::$toMailCallback)->not->toBeNull();

    $message = CoreMails::verifyEmailMessage('https://example.com/email/verify/1/abc');

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and((string) $message->render())->toContain('Acme Mail')->toContain('https://example.com/email/verify/1/abc');
});

it('links the logo to the app, not into the panel, in starter kit mode', function (): void {
    config()->set('app.url', 'https://app.example.com');

    mailPanel();

    expect(MailTheme::brand()->url)->toStartWith('http://localhost/mailer');

    MailTheme::flush();
    mailPanel(fn (Panel $panel): Panel => $panel->starterKit());

    expect(MailTheme::brand()->url)->toBe('https://app.example.com');
});

it('sends an edited template with merge tags filled and the HTML sanitised', function (): void {
    mailPanel();
    Mails::register(MailDefinition::make('tests.order')->label('Order')->editable(['number' => 'Number', 'customer' => 'Customer', 'button' => 'Button']));

    editedTemplates(['tests.order' => ['en' => [
        'subject' => 'Your order {{ number }} for {{ customer }}',
        'body' => '<p>Hello '.MailDefinition::tag('customer').', order {{ number }}</p><script>alert(1)</script><p onclick="x()">'.MailDefinition::tag('button').'</p><p>{{ unknown }}</p>',
    ]]]);

    $mail = new ThemedOrderMail('B-7');
    $html = $mail->render();

    expect($mail->subject)->toBe('Your order B-7 for <b>Ada</b>')
        ->and($html)->toContain('Hello &lt;b&gt;Ada&lt;/b&gt;, order B-7')
        ->toContain('https://example.com/orders/1')
        ->toContain('class="button button-primary"')
        ->toContain('{{ unknown }}')
        ->toContain('Acme Mail')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('Default body');
});

it('only uses an edited template in its own locale (or language)', function (): void {
    mailPanel();
    editedTemplates(['tests.order' => ['ar' => ['subject' => 'طلب {{ number }}', 'body' => '<p>مرحبا</p>'], 'pt' => ['subject' => null, 'body' => '<p>Olá</p>']]]);

    $english = new ThemedOrderMail;
    $englishHtml = $english->render();

    $arabic = (new ThemedOrderMail)->locale('ar');
    $arabicHtml = $arabic->render();

    $portuguese = (new ThemedOrderMail)->locale('pt_BR');
    $portugueseHtml = $portuguese->render();

    expect($englishHtml)->toContain('Default body')
        ->and($arabic->subject)->toBe('طلب A-100')
        ->and($arabicHtml)->toContain('مرحبا')->toContain('dir="rtl"')
        ->and($portugueseHtml)->toContain('Olá')
        ->and($portuguese->subject)->toBe('Order A-100');
});

it('applies an edited template to notifications', function (): void {
    mailPanel();
    editedTemplates([VerifyEmailAuthentication::TEMPLATE => ['en' => ['subject' => 'Code for {{ name }}', 'body' => '<p>Your code: <strong>'.MailDefinition::tag('code').'</strong></p>']]]);

    $message = (new VerifyEmailAuthentication('555111', 10, 'Ada'))->toMail(new AnonymousNotifiable);

    expect($message->subject)->toBe('Code for Ada')
        ->and((string) $message->render())->toContain('555111</strong>')->not->toContain('Hi Ada');
});

it('finds the merge tags a template uses and rejects unknown ones', function (): void {
    $definition = MailDefinition::make('tests.order')->editable(['number' => 'Number', 'customer' => 'Customer']);

    expect(EditableTemplates::mergeTagsIn('Order {{ number }}', '<p>'.MailDefinition::tag('customer').' {{ secret }}</p>'))
        ->toEqualCanonicalizing(['number', 'customer', 'secret'])
        ->and(EditableTemplates::unknownMergeTags($definition, 'Order {{ number }}', '<p>{{ password }}</p>'))->toBe(['password'])
        ->and(EditableTemplates::unknownMergeTags($definition, '{{ number }}', '<p>'.MailDefinition::tag('customer').'</p>'))->toBe([]);
});

it('opens the mail preview page locally or for authorised users only', function (): void {
    mailPanel();
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $this->actingAs($user);

    config()->set('app.debug', false);
    $this->get('/mailer/mail-preview')->assertForbidden();

    Gate::define(MailPreview::GATE, fn (User $user): bool => $user->email === 'ada@example.com');
    $this->get('/mailer/mail-preview')->assertOk();

    MailPreview::authorize(fn (User $user): bool => false);
    $this->get('/mailer/mail-preview')->assertForbidden();

    MailPreview::authorize(null);
    config()->set('app.debug', true);
    Gate::define(MailPreview::GATE, fn (): bool => false);
    $this->get('/mailer/mail-preview')->assertOk();
});

it('lists the mails and previews one in light, dark, LTR and RTL', function (): void {
    mailPanel();
    config()->set('app.debug', true);
    $this->actingAs(User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']));

    $list = inertiaProps($this->get('/mailer/mail-preview', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']));
    $table = collect($list['page']['content'])->firstWhere('key', 'table');

    expect(json_encode($table))->toContain(VerifyEmailAuthentication::TEMPLATE);

    $preview = inertiaProps($this->get('/mailer/mail-preview?mail='.VerifyEmailAuthentication::TEMPLATE, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']));
    $json = json_encode($preview['page']['content']);

    expect(substr_count($json, '<iframe sandbox=\"\"'))->toBe(4)
        ->and($json)->toContain('dir=&quot;rtl&quot;')
        ->toContain('dir=&quot;ltr&quot;')
        ->toContain('content=&quot;dark&quot;')
        ->and(collect($preview['page']['content'])->firstWhere('key', 'table'))->toBeNull();
});
