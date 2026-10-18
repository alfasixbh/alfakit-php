<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `->authorize()`: a Gate ability, a boolean or a closure (which may return a policy
 * `Response`). An unauthorised action is hidden, unless `authorizationTooltip()` (shown
 * disabled, the policy's message as tooltip) or `authorizationNotification()` (clickable,
 * clicking sends the message as a notification) keeps it visible. Either way it never runs.
 */
trait CanBeAuthorized
{
    protected string|bool|Closure|null $authorization = null;

    protected bool|Closure $hasAuthorizationTooltip = false;

    protected bool|Closure $hasAuthorizationNotification = false;

    protected string|Closure|null $authorizationMessage = null;

    /**
     * The last Gate answer and the subject it was for, so one serialisation doesn't ask
     * the policy several times (a table reuses the action for every row).
     *
     * @var array{0: string, 1: Response}|null
     */
    protected ?array $authorizationResponse = null;

    /**
     * A Gate ability (checked against the record, or the model class when there is no
     * record), a boolean, or a closure returning a boolean or an `Illuminate\Auth\Access\Response`.
     */
    public function authorize(string|bool|Closure|null $ability): static
    {
        $this->authorization = $ability;
        $this->authorizationResponse = null;

        return $this;
    }

    /**
     * Show the action disabled, with the policy response's message as its tooltip, instead
     * of hiding it. Without a message (and no `authorizationMessage()`) it stays hidden.
     */
    public function authorizationTooltip(bool|Closure $condition = true): static
    {
        $this->hasAuthorizationTooltip = $condition;

        return $this;
    }

    /**
     * Keep the action clickable; clicking it sends the policy response's message as a
     * danger notification instead of running it.
     */
    public function authorizationNotification(bool|Closure $condition = true): static
    {
        $this->hasAuthorizationNotification = $condition;

        return $this;
    }

    /**
     * The message shown when the policy denies without one of its own.
     */
    public function authorizationMessage(string|Closure|null $message): static
    {
        $this->authorizationMessage = $message;

        return $this;
    }

    public function getAuthorizationResponse(): Response
    {
        $authorization = $this->authorization;

        if ($authorization === null) {
            return Response::allow();
        }

        if (is_string($authorization)) {
            // Array rows (custom `records()`) have no policy: the ability is checked on its own.
            $subject = $this->getRecordModel() ?? $this->getModel();
            $key = $authorization.'|'.(is_object($subject) ? $subject::class.'#'.json_encode($subject->getKey()).'#'.spl_object_id($subject) : (string) $subject);

            if ($this->authorizationResponse !== null && $this->authorizationResponse[0] === $key) {
                return $this->authorizationResponse[1];
            }

            $response = $subject === null ? Gate::inspect($authorization) : Gate::inspect($authorization, $subject);
            $this->authorizationResponse = [$key, $response];

            return $response;
        }

        if (! $authorization instanceof Closure) {
            return $authorization ? Response::allow() : Response::deny();
        }

        // `isVisible()`, `isDisabled()` and `toSchema()` each ask: run the closure once per
        // record (`record()` forgets the answer).
        $key = 'closure#'.spl_object_id($authorization).'#'.(property_exists($this, 'arguments') ? json_encode($this->arguments) : '');

        if ($this->authorizationResponse !== null && $this->authorizationResponse[0] === $key) {
            return $this->authorizationResponse[1];
        }

        $result = $this->evaluate($authorization);
        $response = $result instanceof Response ? $result : ($result ? Response::allow() : Response::deny());
        $this->authorizationResponse = [$key, $response];

        return $response;
    }

    public function isAuthorized(): bool
    {
        return $this->getAuthorizationResponse()->allowed();
    }

    /**
     * Why the action is not authorised: the policy response's message, else
     * `authorizationMessage()`. Null when authorised or when there is no message.
     */
    public function getAuthorizationMessage(): ?string
    {
        $response = $this->getAuthorizationResponse();

        if ($response->allowed()) {
            return null;
        }

        $message = $response->message();

        if (is_string($message) && $message !== '' && $message !== __('This action is unauthorized.')) {
            return $message;
        }

        $fallback = $this->evaluate($this->authorizationMessage);

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    public function hasAuthorizationTooltip(): bool
    {
        return (bool) $this->evaluate($this->hasAuthorizationTooltip);
    }

    public function hasAuthorizationNotification(): bool
    {
        return (bool) $this->evaluate($this->hasAuthorizationNotification);
    }

    /**
     * Unauthorised, but shown (disabled with a tooltip, or clickable with a notification)
     * because there is a message to show.
     */
    public function isUnauthorizedButVisible(): bool
    {
        if ($this->isAuthorized()) {
            return false;
        }

        if (! $this->hasAuthorizationTooltip() && ! $this->hasAuthorizationNotification()) {
            return false;
        }

        return $this->getAuthorizationMessage() !== null;
    }

    /**
     * Clicking sends the authorisation message instead of running the action.
     */
    public function sendsAuthorizationNotification(): bool
    {
        return $this->isUnauthorizedButVisible() && ! $this->hasAuthorizationTooltip() && $this->hasAuthorizationNotification();
    }
}
