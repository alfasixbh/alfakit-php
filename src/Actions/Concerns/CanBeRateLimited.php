<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Notifications\Notification;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `->rateLimit(5)`: at most five runs per minute for each user (or IP address for
 * guests). Further attempts send a "too many attempts" notification and halt the action,
 * leaving its modal open.
 */
trait CanBeRateLimited
{
    protected int|Closure|null $rateLimit = null;

    protected int|Closure $rateLimitDecaySeconds = 60;

    protected Notification|Closure|null $rateLimitedNotification = null;

    protected string|Closure|null $rateLimitedNotificationTitle = null;

    public function rateLimit(int|Closure|null $maxAttempts, int|Closure $decaySeconds = 60): static
    {
        $this->rateLimit = $maxAttempts;
        $this->rateLimitDecaySeconds = $decaySeconds;

        return $this;
    }

    public function getRateLimit(): ?int
    {
        $limit = $this->evaluate($this->rateLimit);

        return $limit === null ? null : (int) $limit;
    }

    public function rateLimitedNotification(Notification|Closure|null $notification): static
    {
        $this->rateLimitedNotification = $notification;

        return $this;
    }

    public function rateLimitedNotificationTitle(string|Closure|null $title): static
    {
        $this->rateLimitedNotificationTitle = $title;

        return $this;
    }

    /**
     * The limiter key: one bucket per panel, owning page or component, action path and
     * user (or IP address).
     */
    public function getRateLimitKey(): string
    {
        $context = $this->getContext();
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->id();

        return 'alfakit-action:'.sha1(implode('|', [
            alfakit()->getCurrentPanel()?->getId() ?? '',
            is_object($context) ? $context::class : '',
            method_exists($this, 'getActionPath') ? $this->getActionPath() : $this->getName(),
            $user !== null ? 'user:'.$user : 'ip:'.request()->ip(),
        ]));
    }

    /**
     * Notify and halt when the limit is reached. Called before the form is validated, so a
     * limited user gets the notification instead of validation errors.
     *
     * @throws Halt
     */
    protected function ensureActionIsNotRateLimited(): void
    {
        $limit = $this->getRateLimit();

        if ($limit === null) {
            return;
        }

        $key = $this->getRateLimitKey();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $this->getRateLimitedNotification(RateLimiter::availableIn($key))?->send();

            throw new Halt;
        }
    }

    /**
     * Count this run against the limit. Called once the form is valid: a submission that only
     * fails validation (a typo in an e-mail address) does not use up the user's attempts.
     */
    protected function hitRateLimit(): void
    {
        if ($this->getRateLimit() === null) {
            return;
        }

        RateLimiter::hit($this->getRateLimitKey(), max(1, (int) $this->evaluate($this->rateLimitDecaySeconds)));
    }

    public function getRateLimitedNotification(int $seconds): ?Notification
    {
        $minutes = (int) ceil($seconds / 60);
        $injections = ['seconds' => $seconds, 'minutes' => $minutes];

        $title = $this->evaluate($this->rateLimitedNotificationTitle, $injections) ?? __('alfakit::actions.rate_limited.title');

        $default = fn (): Notification => Notification::make()
            ->danger()
            ->title($title)
            ->body(trans_choice('alfakit::actions.rate_limited.body', $seconds, ['seconds' => $seconds, 'minutes' => $minutes]));

        $notification = $this->evaluate($this->rateLimitedNotification, [...$injections, 'notification' => $default]);

        return $notification instanceof Notification ? $notification : $default();
    }
}
