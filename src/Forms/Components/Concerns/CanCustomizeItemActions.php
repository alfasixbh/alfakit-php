<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Repeater\ItemAction;
use Closure;

/**
 * Built-in item actions (`deleteAction()`, `addAction()` …) that can be customised like any
 * Action: `->deleteAction(fn (Action $action) => $action->requiresConfirmation())`. The
 * closure receives the action (`$action`, or type-hinted `Action`) and returns it;
 * returning nothing keeps the (modified) instance.
 *
 * Classes using this trait list their actions in `getItemActionNames()` and build them in
 * `makeItemAction()`.
 */
trait CanCustomizeItemActions
{
    /** @var array<string, array<Closure>> */
    protected array $itemActionModifiers = [];

    /**
     * @return array<int, string>
     */
    abstract protected function getItemActionNames(): array;

    abstract protected function makeItemAction(string $name): ItemAction;

    /**
     * Whether the field allows the action at all (`addable()`, `deletable()` …).
     */
    abstract protected function isItemActionEnabled(string $name): bool;

    protected function modifyItemActionUsing(string $name, ?Closure $callback): static
    {
        if ($callback === null) {
            unset($this->itemActionModifiers[$name]);
        } else {
            $this->itemActionModifiers[$name][] = $callback;
        }

        return $this;
    }

    /**
     * The action named `$name`, customised and bound to this field, or null when the
     * field doesn't allow it. Hidden actions are returned; check `isVisible()`.
     */
    public function getItemAction(string $name): ?ItemAction
    {
        if (! in_array($name, $this->getItemActionNames(), true) || ! $this->isItemActionEnabled($name)) {
            return null;
        }

        $action = $this->makeItemAction($name);

        foreach ($this->itemActionModifiers[$name] ?? [] as $modifier) {
            $modified = $this->evaluate($modifier, ['action' => $action], [Action::class => $action, ItemAction::class => $action, $action::class => $action]);

            if ($modified instanceof ItemAction) {
                $action = $modified;
            }
        }

        $action->schemaComponent($this)->fieldComponent($this);

        return $action;
    }

    /**
     * Whether the field allows the action and it is visible (and authorised).
     */
    public function isItemActionAvailable(string $name): bool
    {
        return $this->getItemAction($name)?->isVisible() ?? false;
    }

    /**
     * The visible built-in actions, keyed by name.
     *
     * @return array<string, ItemAction>
     */
    public function getItemActions(): array
    {
        $actions = [];

        foreach ($this->getItemActionNames() as $name) {
            $action = $this->getItemAction($name);

            if ($action !== null && $action->isVisible()) {
                $actions[$name] = $action;
            }
        }

        return $actions;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    protected function getItemActionsSchema(): ?array
    {
        $schemas = [];

        foreach ($this->getItemActions() as $name => $action) {
            $schemas[$name] = $action->toSchema();
        }

        return $schemas === [] ? null : $schemas;
    }

    /**
     * A translated default label, or the English fallback while the key is missing.
     */
    protected static function itemActionLabel(string $key, string $fallback): string
    {
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : $fallback;
    }
}
