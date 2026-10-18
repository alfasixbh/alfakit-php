<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\EmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\AuthKit\Support\Fortify;
use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\OneTimeCodeInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Labels;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Two-step verification after a correct password: a six-digit code from the authenticator
 * app or email (`OneTimeCodeInput`), or a recovery code. With `fortify()`, users with
 * Fortify's two-factor authentication answer it here too (`?method=fortify`).
 */
class TwoFactorChallenge extends AuthKitComponent
{
    /** Pseudo method for single-use recovery codes. */
    public const RECOVERY = 'recovery';

    /** Fortify's two-factor authentication (and `fortify-recovery` for its recovery codes). */
    public const FORTIFY = 'fortify';

    public const FORTIFY_RECOVERY = 'fortify-recovery';

    public ?string $method = null;

    public function getTitle(): string
    {
        return __('alfakit::multi-factor.challenge.title');
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? __('alfakit::multi-factor.challenge.heading');
    }

    public function getSubheading(): ?string
    {
        $user = $this->getChallengedUser();

        if ($user === null) {
            return null;
        }

        return match (true) {
            $this->isFortify() && $this->method === self::FORTIFY_RECOVERY => __('alfakit::multi-factor.recovery.challenge'),
            $this->isFortify() => __('alfakit::multi-factor.app.challenge'),
            $this->isUsingRecoveryCode($user) => __('alfakit::multi-factor.recovery.challenge'),
            default => $this->getCurrentProvider($user)?->getChallengeDescription($user),
        };
    }

    public function getChallengedUser(): ?Authenticatable
    {
        return MultiFactorAuthentication::getChallengedUser($this->getPanel());
    }

    /**
     * Called when the page opens (not on internal requests): emails the code when the
     * current method sends one.
     */
    public function beforeChallenge(): void
    {
        $user = $this->getChallengedUser();

        if ($user !== null && ! $this->isFortify() && ! $this->isUsingRecoveryCode($user)) {
            $this->getCurrentProvider($user)?->beforeChallenge($user);
        }
    }

    public function isFortify(): bool
    {
        $user = $this->getChallengedUser();

        return in_array($this->method, [self::FORTIFY, self::FORTIFY_RECOVERY], true)
            && $this->getAuthKit()->usesFortify()
            && $user !== null
            && Fortify::hasTwoFactor($user);
    }

    /**
     * The method being answered: the one in the URL when the user has it, else their first.
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
        $isRecovery = $user !== null && ($this->isUsingRecoveryCode($user) || ($this->isFortify() && $this->method === self::FORTIFY_RECOVERY));
        $days = $this->isFortify() ? null : $this->getPanel()->getMultiFactorAuthenticationRememberDeviceDays();

        return $schema->components(array_values(array_filter([
            // Recovery codes aren't numeric; authenticator and emailed codes are six digits.
            $isRecovery
                ? TextInput::make('code')
                    ->label(__('alfakit::multi-factor.recovery.code'))
                    ->required()
                    ->autofocus()
                    ->autocomplete('off')
                    ->maxLength(64)
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
        $provider = $user === null || $this->isFortify() ? null : $this->getCurrentProvider($user);

        return array_values(array_filter([
            Action::make('verify')
                ->label(__('alfakit::multi-factor.challenge.submit'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->verify($data)),
            $user !== null && $provider instanceof EmailAuthentication && ! $this->isUsingRecoveryCode($user)
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
     * Five wrong codes per user lock the challenge for five minutes.
     *
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

        $isValid = match (true) {
            $this->isFortify() && $this->method === self::FORTIFY_RECOVERY => Fortify::useRecoveryCode($user, $code),
            $this->isFortify() => Fortify::verifyCode($user, $code),
            $this->isUsingRecoveryCode($user) => (bool) $this->getRecoveryProvider($user)?->verifyRecoveryCode($user, $code),
            default => (bool) $this->getCurrentProvider($user)?->verifyChallengeCode($user, $code),
        };

        if (! $isValid) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages(['code' => __('alfakit::multi-factor.invalid_code')]);
        }

        return MultiFactorAuthentication::completeChallenge($panel, $user, ! $this->isFortify() && (bool) ($data['remember_device'] ?? false));
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

        if ($user !== null && $this->isFortify()) {
            $links[] = $this->method === self::FORTIFY_RECOVERY
                ? ['label' => __('alfakit::multi-factor.challenge.use', ['method' => Labels::forSentence(__('alfakit::multi-factor.app.label'))]), 'url' => $panel->getMultiFactorChallengeUrl(self::FORTIFY)]
                : ['label' => __('alfakit::multi-factor.recovery.use'), 'url' => $panel->getMultiFactorChallengeUrl(self::FORTIFY_RECOVERY)];
        } elseif ($user !== null) {
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

        $links[] = ['label' => __('alfakit::multi-factor.challenge.back'), 'url' => $panel->getLoginUrl()];

        return array_values(array_filter([
            $form === null ? null : $this->serializeForm($form),
            $this->linksBlock($links),
        ]));
    }
}
