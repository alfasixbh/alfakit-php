<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Email;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Auth\Rules\ThrottledRule;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\Forms\Components\OneTimeCodeInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * One-time codes sent to the user's email address.
 *
 * ```php
 * $panel->multiFactorAuthentication([EmailAuthentication::make()->codeExpiryMinutes(5)]);
 * ```
 */
class EmailAuthentication implements MultiFactorAuthenticationProvider
{
    protected int $codeExpiryMinutes = 10;

    final public function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    public function codeExpiryMinutes(int $minutes): static
    {
        $this->codeExpiryMinutes = max(1, $minutes);

        return $this;
    }

    public function getCodeExpiryMinutes(): int
    {
        return $this->codeExpiryMinutes;
    }

    public function getId(): string
    {
        return 'email';
    }

    public function getLabel(): string
    {
        return __('alfakit::multi-factor.email.label');
    }

    public function isSupportedBy(Authenticatable $user): bool
    {
        return $user instanceof HasEmailAuthentication;
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof HasEmailAuthentication && $user->hasEmailAuthentication();
    }

    public function getFingerprint(Authenticatable $user): string
    {
        return hash('sha256', (string) $this->getEmail($user));
    }

    public function getChallengeDescription(Authenticatable $user): string
    {
        return __('alfakit::multi-factor.email.challenge', ['email' => $this->maskEmail((string) $this->getEmail($user))]);
    }

    /**
     * Email a code unless an unexpired one was already sent for this sign-in.
     */
    public function beforeChallenge(Authenticatable $user): void
    {
        if (! $this->hasPendingCode($user)) {
            $this->sendCode($user);
        }
    }

    public function verifyChallengeCode(Authenticatable $user, string $code): bool
    {
        $stored = session()->get($this->sessionKey());

        if (! is_array($stored) || (string) ($stored['user'] ?? '') !== (string) $user->getAuthIdentifier() || (int) ($stored['expires'] ?? 0) < time()) {
            return false;
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! hash_equals((string) ($stored['hash'] ?? ''), MultiFactorAuthentication::hashRecoveryCode($code))) {
            return false;
        }

        session()->forget($this->sessionKey());

        // A used code no longer holds back the next one: signing in right after setting
        // the method up (or after another sign-in) emails a fresh code at once.
        RateLimiter::clear($this->throttleKey($user));

        return true;
    }

    /**
     * Send a new code (at most one per minute).
     */
    public function sendCode(Authenticatable $user): bool
    {
        $email = $this->getEmail($user);
        $throttleKey = $this->throttleKey($user);

        if ($email === null || RateLimiter::tooManyAttempts($throttleKey, 1)) {
            return false;
        }

        RateLimiter::hit($throttleKey, 60);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        session()->put($this->sessionKey(), [
            'user' => $user->getAuthIdentifier(),
            'hash' => MultiFactorAuthentication::hashRecoveryCode($code),
            'expires' => now()->addMinutes($this->codeExpiryMinutes)->getTimestamp(),
        ]);

        $notification = new VerifyEmailAuthentication($code, $this->codeExpiryMinutes, alfakit()->getCurrentPanel()?->getUserName($user));

        if (method_exists($user, 'notify')) {
            $user->notify($notification);
        } else {
            NotificationFacade::route('mail', $email)->notify($notification);
        }

        return true;
    }

    protected function throttleKey(Authenticatable $user): string
    {
        return 'alfakit-mfa-email|'.$user->getAuthIdentifier();
    }

    public function hasPendingCode(Authenticatable $user): bool
    {
        $stored = session()->get($this->sessionKey());

        return is_array($stored)
            && (string) ($stored['user'] ?? '') === (string) $user->getAuthIdentifier()
            && (int) ($stored['expires'] ?? 0) >= time();
    }

    // ---------------------------------------------------------------- Profile page

    public function getManagementSchemaComponents(Authenticatable $user): array
    {
        if (! $this->isSupportedBy($user)) {
            return [];
        }

        $isEnabled = $this->isEnabled($user);

        return [
            Section::make($this->getLabel())
                ->description(__('alfakit::multi-factor.email.description'))
                ->icon('mail')
                ->columns(1)
                ->schema([
                    Text::make($isEnabled ? __('alfakit::multi-factor.enabled') : __('alfakit::multi-factor.not_enabled'))
                        ->badge()
                        ->color($isEnabled ? Color::Success : Color::Gray),
                ])
                ->headerActions([$isEnabled ? $this->makeDisableAction() : $this->makeSetUpAction()]),
        ];
    }

    protected function makeSetUpAction(): Action
    {
        return Action::make('setUpEmailAuthentication')
            ->label(__('alfakit::multi-factor.set_up'))
            ->button()
            ->modalHeading(__('alfakit::multi-factor.email.set_up_heading'))
            ->modalDescription(fn (): string => __('alfakit::multi-factor.email.set_up_description', ['email' => $this->maskEmail((string) $this->getEmail($this->currentUser()))]))
            ->modalSubmitActionLabel(__('alfakit::multi-factor.confirm'))
            ->modalWidth('md')
            // Opening the modal sends the code that confirms the address still works.
            ->fillForm(function (): array {
                $this->beforeChallenge($this->currentUser());

                return [];
            })
            ->form([
                OneTimeCodeInput::make('code')
                    ->label(__('alfakit::multi-factor.code'))
                    ->required()
                    ->autofocus()
                    ->submitOnCompletion(),
            ])
            ->action(function (array $data): void {
                $user = $this->currentUser();

                Throttle::attempt(Throttle::userKey('set-up-email-authentication', $user), 'code');

                if (! $user instanceof HasEmailAuthentication || ! $this->verifyChallengeCode($user, (string) ($data['code'] ?? ''))) {
                    throw ValidationException::withMessages(['code' => __('alfakit::multi-factor.invalid_code')]);
                }

                $user->toggleEmailAuthentication(true);

                Notification::make()->success()->title(__('alfakit::multi-factor.email.enabled'))->send();
            });
    }

    protected function makeDisableAction(): Action
    {
        return Action::make('removeEmailAuthentication')
            ->label(__('alfakit::multi-factor.remove'))
            ->button()
            ->outlined()
            ->color(Color::Danger)
            ->modalHeading(__('alfakit::multi-factor.email.remove_heading'))
            ->modalDescription(__('alfakit::multi-factor.remove_description'))
            ->modalSubmitActionLabel(__('alfakit::multi-factor.remove'))
            ->form([
                TextInput::make('current_password')
                    ->label(__('alfakit::multi-factor.current_password'))
                    ->password()
                    ->revealable($this->panel()->arePasswordsRevealable())
                    ->required()
                    ->autocomplete('current-password')
                    ->rule(fn (): ThrottledRule => Throttle::currentPasswordRule(Throttle::userKey('disable-email-authentication', panel: $this->panel()), $this->panel())),
            ])
            ->action(function (): void {
                $user = $this->currentUser();

                if ($user instanceof HasEmailAuthentication) {
                    $user->toggleEmailAuthentication(false);
                }

                Notification::make()->success()->title(__('alfakit::multi-factor.email.disabled'))->send();
            });
    }

    protected function getEmail(Authenticatable $user): ?string
    {
        $email = method_exists($user, 'getEmailForVerification')
            ? $user->getEmailForVerification()
            : (method_exists($user, 'getAttribute') ? $user->getAttribute('email') : null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    protected function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).str_repeat('•', max(1, mb_strlen($name) - 1)).($domain !== '' ? '@'.$domain : '');
    }

    protected function sessionKey(): string
    {
        return MultiFactorAuthentication::sessionKey($this->panel()).'-email-code';
    }

    protected function currentUser(): Authenticatable
    {
        $user = $this->panel()->auth()->user();

        abort_if($user === null, 401);

        return $user;
    }

    protected function panel(): Panel
    {
        return alfakit()->getCurrentOrDefaultPanel();
    }
}
