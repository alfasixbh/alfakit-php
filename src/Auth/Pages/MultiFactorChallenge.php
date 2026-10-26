<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\EmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\OneTimeCodeInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Labels;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Shown after a correct password when the user has a second factor: asks for a code from
 * their authenticator app or email (or a recovery code) before signing them in.
 */
class MultiFactorChallenge extends Page
{
    /** Pseudo method for single-use recovery codes. */
    public const RECOVERY = 'recovery';

    // Registered by the panel's `login()`, `registration()`… only, never discovered as a page:
    // a subclass in a discovered folder would otherwise also become a panel page (W-S1-15).
    protected static bool $isDiscovered = false;

    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $method = null;

    public static function requiresAuthentication(): bool
    {
        return false;
    }

    public static function getRelativeRouteName(): string
    {
        return 'auth.multi-factor-challenge';
    }

    public function __invoke(Request $request): mixed
    {
        $panel = $this->getPanel();
        $user = MultiFactorAuthentication::getChallengedUser($panel);

        if ($user === null) {
            return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
        }

        $method = $request->route('method');
        $this->method = is_string($method) ? $method : null;

        // Only the page itself triggers side effects such as emailing a code; internal
        // requests (submitting the code) rebuild the page without them.
        $this->getCurrentProvider($user)?->beforeChallenge($user);

        return parent::__invoke($request);
    }

    public function mount(mixed ...$parameters): void
    {
        $method = $parameters[0] ?? null;
        $this->method = is_string($method) ? $method : null;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->method = null;
    }

    public function getTitle(): string
    {
        return __('alfakit::multi-factor.challenge.title');
    }

    public function getHeading(): ?string
    {
        return __('alfakit::multi-factor.challenge.heading');
    }

    public function getSubheading(): ?string
    {
        $user = $this->getChallengedUser();

        if ($user === null) {
            return null;
        }

        if ($this->isUsingRecoveryCode($user)) {
            return __('alfakit::multi-factor.recovery.challenge');
        }

        return $this->getCurrentProvider($user)?->getChallengeDescription($user);
    }

    public function getChallengedUser(): ?Authenticatable
    {
        return MultiFactorAuthentication::getChallengedUser($this->getPanel());
    }

    /**
     * The method the user is answering: the one in the URL when they have it set up,
     * otherwise their first one.
     */
    public function getCurrentProvider(Authenticatable $user): ?MultiFactorAuthenticationProvider
    {
        $providers = MultiFactorAuthentication::getEnabledProviders($this->getPanel(), $user);

        if ($this->method !== null && isset($providers[$this->method])) {
            return $providers[$this->method];
        }

        return $providers[array_key_first($providers) ?? ''] ?? null;
    }

    public function isUsingRecoveryCode(Authenticatable $user): bool
    {
        return $this->method === self::RECOVERY && $this->getRecoveryProvider($user) !== null;
    }

    protected function getRecoveryProvider(Authenticatable $user): ?AppAuthentication
    {
        $provider = MultiFactorAuthentication::getEnabledProviders($this->getPanel(), $user)['app'] ?? null;

        return $provider instanceof AppAuthentication && $provider->hasRecoveryCodes($user) ? $provider : null;
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->getChallengedUser();
        $isRecovery = $user !== null && $this->isUsingRecoveryCode($user);
        $days = $this->getPanel()->getMultiFactorAuthenticationRememberDeviceDays();

        return $schema->components(array_values(array_filter([
            // Recovery codes aren't numeric; authenticator and emailed codes are six digits.
            $isRecovery
                ? TextInput::make('code')
                    ->label(__('alfakit::multi-factor.recovery.code'))
                    ->required()
                    ->autofocus()
                    ->autocomplete('off')
                    ->maxLength(32)
                : OneTimeCodeInput::make('code')
                    ->label(__('alfakit::multi-factor.code'))
                    ->required()
                    ->autofocus()
                    ->submitOnCompletion(),
            $days === null ? null : Checkbox::make('remember_device')
                ->label(trans_choice('alfakit::multi-factor.challenge.remember_device', $days, ['days' => $days])),
        ])))->columns(1);
    }

    protected function getFormActions(): array
    {
        $user = $this->getChallengedUser();
        $provider = $user === null ? null : $this->getCurrentProvider($user);

        return array_values(array_filter([
            Action::make('verify')
                ->label(__('alfakit::multi-factor.challenge.submit'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->verify($data)),
            $provider instanceof EmailAuthentication && ! $this->isUsingRecoveryCode($user)
                ? Action::make('resend')
                    ->label(__('alfakit::multi-factor.email.resend'))
                    ->color(Color::Gray)
                    ->ghost()
                    ->button()
                    ->extraAttributes(['class' => 'w-full'])
                    ->action(fn () => $this->resend())
                : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function verify(array $data): mixed
    {
        $panel = $this->getPanel();
        $user = $this->getChallengedUser();

        if ($user === null) {
            throw ValidationException::withMessages(['code' => __('alfakit::multi-factor.challenge.expired')]);
        }

        $throttleKey = MultiFactorAuthentication::throttleKey($panel, $user);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => __('alfakit::multi-factor.challenge.throttled', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $code = trim((string) ($data['code'] ?? ''));

        $isValid = $this->isUsingRecoveryCode($user)
            ? (bool) $this->getRecoveryProvider($user)?->verifyRecoveryCode($user, $code)
            : (bool) $this->getCurrentProvider($user)?->verifyChallengeCode($user, $code);

        if (! $isValid) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages(['code' => __('alfakit::multi-factor.invalid_code')]);
        }

        return MultiFactorAuthentication::completeChallenge($panel, $user, (bool) ($data['remember_device'] ?? false));
    }

    public function resend(): mixed
    {
        $user = $this->getChallengedUser();
        $provider = $user === null ? null : $this->getCurrentProvider($user);

        if ($user !== null && $provider instanceof EmailAuthentication && $provider->sendCode($user)) {
            Notification::make()->success()->title(__('alfakit::multi-factor.email.sent'))->send();
        } else {
            Notification::make()->warning()->title(__('alfakit::multi-factor.email.throttled'))->send();
        }

        return back();
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();
        $user = $this->getChallengedUser();
        $panel = $this->getPanel();
        $links = [];

        if ($user !== null) {
            $current = $this->isUsingRecoveryCode($user) ? self::RECOVERY : $this->getCurrentProvider($user)?->getId();

            foreach (MultiFactorAuthentication::getEnabledProviders($panel, $user) as $id => $provider) {
                if ($id !== $current) {
                    $links[] = ['label' => __('alfakit::multi-factor.challenge.use', ['method' => Labels::forSentence($provider->getLabel())]), 'url' => $panel->getMultiFactorChallengeUrl($id)];
                }
            }

            if ($current !== self::RECOVERY && $this->getRecoveryProvider($user) !== null) {
                $links[] = ['label' => __('alfakit::multi-factor.recovery.use'), 'url' => $panel->getMultiFactorChallengeUrl(self::RECOVERY)];
            }
        }

        if (($login = $panel->getLoginUrl()) !== null) {
            $links[] = ['label' => __('alfakit::multi-factor.challenge.back'), 'url' => $login];
        }

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $links === [] ? null : ['type' => 'Links', 'key' => 'links', 'links' => $links],
        ]));
    }
}
