<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\App;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Auth\Rules\ThrottledRule;
use AlfaSix\Alfakit\Auth\Throttle;
use AlfaSix\Alfakit\Forms\Components\OneTimeCodeInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Support\Enums\Color;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Codes from an authenticator app (Google Authenticator, 1Password, Authy…), set up by
 * scanning a QR code on the profile page. With `recoverable()`, users also get single-use
 * recovery codes for when they lose the device.
 *
 * ```php
 * $panel->multiFactorAuthentication([AppAuthentication::make()->recoverable()]);
 * ```
 */
class AppAuthentication implements MultiFactorAuthenticationProvider
{
    protected bool $isRecoverable = false;

    protected int $recoveryCodeCount = 8;

    protected bool $areRecoveryCodesRegenerable = true;

    protected int $codeWindow = 1;

    protected ?string $brandName = null;

    final public function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    public function recoverable(bool $condition = true): static
    {
        $this->isRecoverable = $condition;

        return $this;
    }

    public function isRecoverable(): bool
    {
        return $this->isRecoverable;
    }

    /**
     * `regenerableRecoveryCodes(false)`: users can't generate new recovery codes from
     * their profile (they get one set when they set up the app).
     */
    public function regenerableRecoveryCodes(bool $condition = true): static
    {
        $this->areRecoveryCodesRegenerable = $condition;

        return $this;
    }

    public function areRecoveryCodesRegenerable(): bool
    {
        return $this->isRecoverable && $this->areRecoveryCodesRegenerable;
    }

    public function recoveryCodeCount(int $count): static
    {
        $this->recoveryCodeCount = max(1, $count);

        return $this;
    }

    public function getRecoveryCodeCount(): int
    {
        return $this->recoveryCodeCount;
    }

    /**
     * How many 30-second steps before and after now a code is accepted (clock drift).
     */
    public function codeWindow(int $steps): static
    {
        $this->codeWindow = max(0, $steps);

        return $this;
    }

    /**
     * The issuer shown in the authenticator app (defaults to the panel's brand name).
     */
    public function brandName(?string $name): static
    {
        $this->brandName = $name;

        return $this;
    }

    public function getBrandName(): string
    {
        return $this->brandName ?? $this->panel()->getBrandName();
    }

    public function getId(): string
    {
        return 'app';
    }

    public function getLabel(): string
    {
        return __('alfakit::multi-factor.app.label');
    }

    public function isSupportedBy(Authenticatable $user): bool
    {
        return $user instanceof HasAppAuthentication;
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof HasAppAuthentication && filled($user->getAppAuthenticationSecret());
    }

    public function getFingerprint(Authenticatable $user): string
    {
        return $user instanceof HasAppAuthentication ? hash('sha256', (string) $user->getAppAuthenticationSecret()) : '';
    }

    public function getChallengeDescription(Authenticatable $user): string
    {
        return __('alfakit::multi-factor.app.challenge');
    }

    public function beforeChallenge(Authenticatable $user): void {}

    public function verifyChallengeCode(Authenticatable $user, string $code): bool
    {
        if (! $user instanceof HasAppAuthentication) {
            return false;
        }

        $secret = $user->getAppAuthenticationSecret();

        return $secret !== null && $this->verifyCode($user, $secret, $code);
    }

    /**
     * Check a code and remember its time step, so the same code can't be replayed.
     */
    public function verifyCode(Authenticatable $user, string $secret, string $code): bool
    {
        $cacheKey = 'alfakit-mfa-app-step|'.$user->getAuthIdentifier();

        // Locked so two concurrent requests can't both pass with the same code.
        return $this->whileLocked($cacheKey.'|lock', function () use ($cacheKey, $secret, $code): bool {
            $last = Cache::get($cacheKey);

            $step = Totp::verify($secret, $code, $this->codeWindow, is_int($last) ? $last : null);

            if ($step === null) {
                return false;
            }

            Cache::put($cacheKey, $step, now()->addSeconds(Totp::PERIOD * (2 * $this->codeWindow + 2)));

            return true;
        });
    }

    /**
     * Use up one recovery code. Returns false when it doesn't match an unused code.
     *
     * Read, compare and save run under a per-user cache lock and, for Eloquent users, in
     * a transaction that re-reads the user row with `lockForUpdate()`, so two concurrent
     * requests can't spend the same code.
     */
    public function verifyRecoveryCode(Authenticatable $user, string $code): bool
    {
        if (! $this->isRecoverable || ! $user instanceof HasAppAuthenticationRecovery) {
            return false;
        }

        $hash = MultiFactorAuthentication::hashRecoveryCode($code);

        return $this->whileLocked('alfakit-mfa-recovery|'.$user->getAuthIdentifier(), function () use ($user, $hash): bool {
            $consume = static function () use ($user, $hash): bool {
                $codes = $user->getAppAuthenticationRecoveryCodes() ?? [];

                foreach ($codes as $index => $stored) {
                    if (hash_equals($stored, $hash)) {
                        unset($codes[$index]);
                        $user->saveAppAuthenticationRecoveryCodes(array_values($codes));

                        return true;
                    }
                }

                return false;
            };

            if (! $user instanceof Model || ! $user->exists) {
                return $consume();
            }

            return (bool) $user->getConnection()->transaction(static function () use ($user, $consume): bool {
                // The instance may have been loaded before another request used a code.
                $fresh = $user->newQueryWithoutScopes()->whereKey($user->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    return false;
                }

                $user->setRawAttributes($fresh->getAttributes(), sync: true);

                return $consume();
            });
        });
    }

    /**
     * Run a read-compare-save step under a per-user cache lock. Returns false when the
     * lock can't be acquired in time (another request is using the same user's codes).
     *
     * @param  Closure(): bool  $callback
     */
    protected function whileLocked(string $key, Closure $callback): bool
    {
        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return (bool) $store->lock($key, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    public function hasRecoveryCodes(Authenticatable $user): bool
    {
        return $this->isRecoverable && $user instanceof HasAppAuthenticationRecovery && ($user->getAppAuthenticationRecoveryCodes() ?? []) !== [];
    }

    /**
     * Replace the user's recovery codes and keep the plain codes in the session so the
     * profile page shows them once.
     *
     * @return array<string>
     */
    public function regenerateRecoveryCodes(Authenticatable $user): array
    {
        if (! $user instanceof HasAppAuthenticationRecovery) {
            return [];
        }

        $codes = MultiFactorAuthentication::generateRecoveryCodes($this->recoveryCodeCount);
        $user->saveAppAuthenticationRecoveryCodes(array_map(MultiFactorAuthentication::hashRecoveryCode(...), $codes));
        MultiFactorAuthentication::flashRecoveryCodes($this->panel(), $codes);

        return $codes;
    }

    // ---------------------------------------------------------------- Set-up

    /**
     * The secret being set up, kept in the session between showing the QR code and
     * confirming the first code.
     */
    public function getPendingSecret(): string
    {
        $key = MultiFactorAuthentication::sessionKey($this->panel()).'-app-setup';
        $secret = session()->get($key);

        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            session()->put($key, $secret);
        }

        return $secret;
    }

    public function forgetPendingSecret(): void
    {
        session()->forget(MultiFactorAuthentication::sessionKey($this->panel()).'-app-setup');
    }

    public function getQrCodeUri(Authenticatable $user, string $secret): string
    {
        $holder = $user instanceof HasAppAuthentication ? $user->getAppAuthenticationHolderName() : (string) $user->getAuthIdentifier();

        return Totp::uri($secret, $this->getBrandName(), $holder);
    }

    /**
     * The QR code as an SVG data URI.
     */
    public function getQrCodeDataUri(Authenticatable $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($this->getQrCodeUri($user, $secret)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function confirmSetUp(Authenticatable $user, array $data): void
    {
        if (! $user instanceof HasAppAuthentication) {
            abort(403);
        }

        // Every code counts, so a set-up code can't be guessed by retrying.
        Throttle::attempt(Throttle::userKey('set-up-app-authentication', $user), 'code');

        $secret = $this->getPendingSecret();

        if (! $this->verifyCode($user, $secret, (string) ($data['code'] ?? ''))) {
            throw ValidationException::withMessages(['code' => __('alfakit::multi-factor.invalid_code')]);
        }

        $user->saveAppAuthenticationSecret($secret);
        $this->forgetPendingSecret();

        if ($this->isRecoverable) {
            $this->regenerateRecoveryCodes($user);
        }

        Notification::make()->success()->title(__('alfakit::multi-factor.app.enabled'))->send();
    }

    public function disable(Authenticatable $user): void
    {
        if ($user instanceof HasAppAuthentication) {
            $user->saveAppAuthenticationSecret(null);
        }

        if ($user instanceof HasAppAuthenticationRecovery) {
            $user->saveAppAuthenticationRecoveryCodes(null);
        }

        MultiFactorAuthentication::forgetFlashedRecoveryCodes($this->panel());

        Notification::make()->success()->title(__('alfakit::multi-factor.app.disabled'))->send();
    }

    // ---------------------------------------------------------------- Profile page

    public function getManagementSchemaComponents(Authenticatable $user): array
    {
        if (! $this->isSupportedBy($user)) {
            return [];
        }

        $isEnabled = $this->isEnabled($user);
        $panel = $this->panel();
        $freshCodes = $isEnabled ? MultiFactorAuthentication::getFlashedRecoveryCodes($panel) : null;

        $components = [
            Text::make($isEnabled ? __('alfakit::multi-factor.enabled') : __('alfakit::multi-factor.not_enabled'))
                ->badge()
                ->color($isEnabled ? Color::Success : Color::Gray),
        ];

        if ($isEnabled && $this->isRecoverable && $user instanceof HasAppAuthenticationRecovery) {
            $components[] = Text::make(trans_choice('alfakit::multi-factor.recovery.remaining', $remaining = count($user->getAppAuthenticationRecoveryCodes() ?? []), ['count' => $remaining]))
                ->color(Color::Gray);
        }

        if ($freshCodes !== null) {
            $components[] = Section::make(__('alfakit::multi-factor.recovery.heading'))
                ->description(__('alfakit::multi-factor.recovery.description'))
                ->compact()
                ->secondary()
                ->columns(1)
                ->schema([Html::make(new HtmlString($this->renderRecoveryCodes($freshCodes)))])
                ->footerActions([
                    Action::make('downloadAppAuthenticationRecoveryCodes')
                        ->label(__('alfakit::multi-factor.recovery.download'))
                        ->icon('download')
                        ->button()
                        ->outlined()
                        ->url($panel->getRecoveryCodesDownloadUrl(), shouldOpenInNewTab: true),
                    Action::make('dismissAppAuthenticationRecoveryCodes')
                        ->label(__('alfakit::multi-factor.recovery.dismiss'))
                        ->color(Color::Gray)
                        ->button()
                        ->ghost()
                        ->action(static fn () => MultiFactorAuthentication::forgetFlashedRecoveryCodes($panel)),
                ]);
        }

        return [
            Section::make($this->getLabel())
                ->description(__('alfakit::multi-factor.app.description'))
                ->icon('smartphone')
                ->columns(1)
                ->schema($components)
                ->headerActions(array_values(array_filter($isEnabled ? [
                    $this->areRecoveryCodesRegenerable() ? $this->makeRegenerateRecoveryCodesAction() : null,
                    $this->makeDisableAction(),
                ] : [
                    $this->makeSetUpAction(),
                ]))),
        ];
    }

    protected function makeSetUpAction(): Action
    {
        return Action::make('setUpAppAuthentication')
            ->label(__('alfakit::multi-factor.set_up'))
            ->button()
            ->modalHeading(__('alfakit::multi-factor.app.set_up_heading'))
            ->modalDescription(__('alfakit::multi-factor.app.set_up_description'))
            ->modalSubmitActionLabel(__('alfakit::multi-factor.confirm'))
            ->modalWidth('md')
            ->form(fn (): array => [
                Html::make(fn (): HtmlString => new HtmlString($this->renderQrCode($this->currentUser()))),
                OneTimeCodeInput::make('code')
                    ->label(__('alfakit::multi-factor.code'))
                    ->required()
                    ->autofocus()
                    ->submitOnCompletion(),
            ])
            ->action(fn (array $data) => $this->confirmSetUp($this->currentUser(), $data));
    }

    protected function makeRegenerateRecoveryCodesAction(): Action
    {
        return Action::make('regenerateAppAuthenticationRecoveryCodes')
            ->label(__('alfakit::multi-factor.recovery.regenerate'))
            ->button()
            ->outlined()
            ->color(Color::Gray)
            ->modalHeading(__('alfakit::multi-factor.recovery.regenerate'))
            ->modalDescription(__('alfakit::multi-factor.recovery.regenerate_description'))
            ->modalSubmitActionLabel(__('alfakit::multi-factor.recovery.regenerate'))
            ->form([$this->makeCurrentPasswordInput('regenerate-recovery-codes')])
            ->action(function (): void {
                $this->regenerateRecoveryCodes($this->currentUser());

                Notification::make()->success()->title(__('alfakit::multi-factor.recovery.regenerated'))->send();
            });
    }

    protected function makeDisableAction(): Action
    {
        return Action::make('removeAppAuthentication')
            ->label(__('alfakit::multi-factor.remove'))
            ->button()
            ->outlined()
            ->color(Color::Danger)
            ->modalHeading(__('alfakit::multi-factor.app.remove_heading'))
            ->modalDescription(__('alfakit::multi-factor.remove_description'))
            ->modalSubmitActionLabel(__('alfakit::multi-factor.remove'))
            ->form([$this->makeCurrentPasswordInput('disable-app-authentication')])
            ->action(fn () => $this->disable($this->currentUser()));
    }

    /**
     * The current-password input of the profile actions. With `$throttle`, every attempt
     * counts against a per-user limiter of that name (five a minute).
     */
    public function makeCurrentPasswordInput(?string $throttle = null): TextInput
    {
        return TextInput::make('current_password')
            ->label(__('alfakit::multi-factor.current_password'))
            ->password()
            ->revealable($this->panel()->arePasswordsRevealable())
            ->required()
            ->autocomplete('current-password')
            ->rule($throttle === null
                ? 'current_password:'.$this->panel()->getAuthGuard()
                : fn (): ThrottledRule => Throttle::currentPasswordRule(Throttle::userKey($throttle, panel: $this->panel()), $this->panel()));
    }

    protected function renderQrCode(Authenticatable $user): string
    {
        $secret = $this->getPendingSecret();

        return sprintf(
            '<div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;text-align:center">'
            .'<img src="%s" alt="%s" width="192" height="192" style="background:#fff;border-radius:0.5rem;padding:0.5rem">'
            .'<span style="font-size:0.875rem;opacity:0.8">%s</span>'
            .'<code dir="ltr" style="font-size:0.875rem;letter-spacing:0.05em;word-break:break-all">%s</code>'
            .'</div>',
            e($this->getQrCodeDataUri($user, $secret)),
            e(__('alfakit::multi-factor.app.qr_alt')),
            e(__('alfakit::multi-factor.app.manual_entry')),
            e(trim(chunk_split($secret, 4, ' '))),
        );
    }

    /**
     * @param  array<string>  $codes
     */
    protected function renderRecoveryCodes(array $codes): string
    {
        $items = implode('', array_map(static fn (string $code): string => '<li><code>'.e($code).'</code></li>', $codes));

        return '<ul dir="ltr" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0.25rem 1rem;font-size:0.875rem;list-style:none;padding:0;margin:0">'.$items.'</ul>';
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
