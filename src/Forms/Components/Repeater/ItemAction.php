<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Repeater;

use AlfaSix\Alfakit\Actions\Action;
use Closure;

/**
 * A built-in action of a Repeater, Builder or KeyValue field (`deleteAction()`,
 * `cloneAction()`, `moveUpAction()` …). It is an ordinary Action, so it can be customised
 * with every Action method; its default handler changes the field's state on the server.
 *
 * Renderers run uncustomised item actions in the browser (no request). As soon as the
 * action needs the server, because it opens a modal (`requiresConfirmation()`, a form),
 * got another handler (`action()`) or lifecycle hooks (`before()`, `after()`), it runs
 * through the field action endpoint with `$arguments['item']` (and `items` / `block`).
 */
class ItemAction extends Action
{
    protected ?Closure $defaultAction = null;

    /** Purely visual actions (collapse, expand) never run on the server. */
    protected bool $isClientOnly = false;

    /**
     * Set the handler the field ships with; replacing it later with `action()` makes the
     * action run on the server.
     */
    public function defaultAction(?Closure $action): static
    {
        $this->defaultAction = $action;
        $this->action = $action;

        return $this;
    }

    public function clientOnly(bool $condition = true): static
    {
        $this->isClientOnly = $condition;

        return $this;
    }

    public function isClientOnly(): bool
    {
        return $this->isClientOnly;
    }

    /**
     * Whether the renderer must run this action through the server instead of applying
     * the change in the browser.
     */
    public function runsOnServer(): bool
    {
        if ($this->isClientOnly) {
            return false;
        }

        return $this->action !== $this->defaultAction
            || $this->before !== []
            || $this->after !== []
            || $this->hasModal()
            || $this->isPasswordConfirmationRequired();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $schema = parent::toSchema();

        if ($this->runsOnServer()) {
            $schema['server'] = true;
        }

        return $schema;
    }
}
