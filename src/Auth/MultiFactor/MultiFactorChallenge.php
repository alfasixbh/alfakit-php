<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Contracts\MountsWithAction;
use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Auth\MultiFactor\Rules\VerifiesMultiFactorChallenge;
use AlfaSix\Alfakit\Forms\Components\Hidden;
use AlfaSix\Alfakit\Forms\Components\OneTimeCodeInput;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Ask the signed-in user for a second factor (authenticator code, emailed code or recovery
 * code) before a sensitive action runs ("sudo mode"):
 *
 * ```php
 * Action::make('deleteAccount')
 *     ->requiresConfirmation()
 *     ->schema([MultiFactorChallenge::make()])
 *     ->action(fn () => ...);
 * ```
 *
 * The code is verified on the server while the modal form is validated, so the action
 * only runs after a correct code (attempts are rate limited with the login challenge's
 * limiter). A user with no second factor set up can't pass it: check
 * `hasEnabledProviders()` first if the action should run for them anyway. Emailed codes
 * are sent when the modal opens. A passed challenge doesn't authorise anything: keep
 * authorising the action itself.
 */
class MultiFactorChallenge extends Group implements MountsWithAction
{
    /** Pseudo method for single-use recovery codes. */
    public const RECOVERY = 'recovery';

    /** Renderers draw it as a plain group of fields. */
    protected ?string $type = 'Group';

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath('multiFactorChallenge');
        $this->schema(fn (): array => $this->getChallengeComponents());
    }

    public function getPanel(): Panel
    {
        return alfakit()->getCurrentOrDefaultPanel();
    }

    public function getUser(): ?Authenticatable
    {
        return $this->getPanel()->auth()->user();
    }

    public function hasEnabledProviders(?Authenticatable $user = null): bool
    {
        $user ??= $this->getUser();

        return $user !== null && $this->getPanel()->hasMultiFactorAuthentication() && MultiFactorAuthentication::getEnabledProviders($this->getPanel(), $user) !== [];
    }

    /**
     * The methods the user can answer with, keyed by id (`app`, `email`, `recovery`).
     *
     * @return array<string, MultiFactorAuthenticationProvider>
     */
    public function getMethods(?Authenticatable $user = null): array
    {
        $user ??= $this->getUser();

        if ($user === null || ! $this->getPanel()->hasMultiFactorAuthentication()) {
            return [];
        }

        $methods = MultiFactorAuthentication::getEnabledProviders($this->getPanel(), $user);
        $app = $methods['app'] ?? null;

        if ($app instanceof AppAuthentication && $app->hasRecoveryCodes($user)) {
            $methods[self::RECOVERY] = $app;
        }

        return $methods;
    }

    public function getMethodLabel(string $method, MultiFactorAuthenticationProvider $provider): string
    {
        return $method === self::RECOVERY ? __('alfakit::multi-factor.recovery.code') : $provider->getLabel();
    }

    /**
     * Send the first method's code (emailed codes) when the modal opens.
     */
    public function mountedWithAction(Action $action): void
    {
        $user = $this->getUser();
        $methods = $this->getMethods($user);
        $first = array_key_first($methods);

        if ($user !== null && $first !== null && $first !== self::RECOVERY) {
            $methods[$first]->beforeChallenge($user);
        }
    }

    /**
     * @return array<Component>
     */
    protected function getChallengeComponents(): array
    {
        $user = $this->getUser();
        $methods = $this->getMethods($user);
        $first = array_key_first($methods);

        $method = count($methods) > 1
            ? Radio::make('method')
                ->label(__('alfakit::multi-factor.challenge.method'))
                ->options(array_map(fn (string $id): string => $this->getMethodLabel($id, $methods[$id]), array_combine(array_keys($methods), array_keys($methods))))
                ->default($first)
                ->required()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(function (mixed $state) use ($user, $methods): void {
                    // Switching to the email method sends its code.
                    if ($user !== null && is_string($state) && $state !== self::RECOVERY && isset($methods[$state])) {
                        $methods[$state]->beforeChallenge($user);
                    }
                })
            : Hidden::make('method')->default($first)->dehydrated(false);

        // Without recovery codes every method uses a six-digit code (`OneTimeCodeInput`).
        $code = isset($methods[self::RECOVERY])
            ? TextInput::make('code')->autocomplete('one-time-code')->maxLength(32)
            : OneTimeCodeInput::make('code')->submitOnCompletion();

        return [
            $method,
            $code
                ->label(fn (Get $get): string => $get('method') === self::RECOVERY ? __('alfakit::multi-factor.recovery.code') : __('alfakit::multi-factor.code'))
                ->helperText(function (Get $get) use ($user, $methods): ?string {
                    $selected = $get('method');

                    if ($user === null || $methods === []) {
                        return __('alfakit::multi-factor.challenge.unavailable');
                    }

                    if ($selected === self::RECOVERY) {
                        return __('alfakit::multi-factor.recovery.challenge');
                    }

                    $provider = is_string($selected) ? ($methods[$selected] ?? null) : null;

                    return $provider?->getChallengeDescription($user);
                })
                ->required()
                ->dehydrated(false)
                ->rule(new VerifiesMultiFactorChallenge($this)),
        ];
    }

    /**
     * State path of the method field, for the verification rule.
     */
    public function getMethodStatePath(): string
    {
        $path = $this->getStatePath();

        return filled($path) ? $path.'.method' : 'method';
    }
}
