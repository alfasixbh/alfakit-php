<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use Closure;

trait HasLifecycleHooks
{
    /** @var array<Closure> */
    protected array $before = [];

    /** @var array<Closure> */
    protected array $after = [];

    /** @var array<Closure> */
    protected array $beforeFormFilled = [];

    /** @var array<Closure> */
    protected array $afterFormFilled = [];

    /** @var array<Closure> */
    protected array $beforeFormValidated = [];

    /** @var array<Closure> */
    protected array $afterFormValidated = [];

    public function before(Closure $callback): static
    {
        $this->before[] = $callback;

        return $this;
    }

    public function after(Closure $callback): static
    {
        $this->after[] = $callback;

        return $this;
    }

    /**
     * Runs when the modal opens, before the form is filled with its initial data.
     */
    public function beforeFormFilled(Closure $callback): static
    {
        $this->beforeFormFilled[] = $callback;

        return $this;
    }

    /**
     * Runs when the modal opens, after the form was filled (`$data` is the filled state).
     */
    public function afterFormFilled(Closure $callback): static
    {
        $this->afterFormFilled[] = $callback;

        return $this;
    }

    /**
     * Runs on submit, before the form is validated (`$data` is the submitted state).
     */
    public function beforeFormValidated(Closure $callback): static
    {
        $this->beforeFormValidated[] = $callback;

        return $this;
    }

    /**
     * Runs on submit, after the form was validated (`$data` is the validated data), before
     * `before()` and the action itself.
     */
    public function afterFormValidated(Closure $callback): static
    {
        $this->afterFormValidated[] = $callback;

        return $this;
    }

    /**
     * @param  'beforeFormFilled'|'afterFormFilled'|'beforeFormValidated'|'afterFormValidated'  $hook
     * @param  array<string, mixed>  $injections
     * @param  array<class-string, mixed>  $typedInjections
     */
    public function callFormHook(string $hook, array $injections = [], array $typedInjections = []): void
    {
        foreach ($this->{$hook} as $callback) {
            $this->evaluate($callback, $injections, $typedInjections);
        }
    }

    /**
     * @param  array<string, mixed>  $injections
     */
    public function callBefore(array $injections = []): void
    {
        foreach ($this->before as $callback) {
            $this->evaluate($callback, $injections);
        }
    }

    /**
     * @param  array<string, mixed>  $injections
     */
    public function callAfter(array $injections = []): void
    {
        foreach ($this->after as $callback) {
            $this->evaluate($callback, $injections);
        }
    }

    /**
     * Stop executing the action but keep its modal open (e.g. after sending a notification).
     */
    public function halt(): never
    {
        throw new Halt;
    }

    /**
     * Stop executing the action and close its modal.
     */
    public function cancel(): never
    {
        throw new Cancel;
    }
}
