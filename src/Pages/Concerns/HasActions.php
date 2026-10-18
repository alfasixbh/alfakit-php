<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Schemas\Schema;
use Closure;

trait HasActions
{
    /** @var array<string, Action>|null */
    protected ?array $cachedActions = null;

    /**
     * Buttons shown next to the page heading.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Buttons shown below the page form (e.g. save / cancel).
     *
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * @return array<Action|ActionGroup>
     */
    public function getCachedHeaderActions(): array
    {
        return $this->prepareActions($this->getHeaderActions());
    }

    /**
     * @return array<Action|ActionGroup>
     */
    public function getCachedFormActions(): array
    {
        return $this->prepareActions($this->getFormActions());
    }

    /**
     * Every page-level action (header, form and schema-embedded), keyed by name.
     *
     * @return array<string, Action>
     */
    public function getActions(): array
    {
        if ($this->cachedActions !== null) {
            return $this->cachedActions;
        }

        $actions = [];

        foreach ([...$this->getCachedHeaderActions(), ...$this->getCachedFormActions()] as $action) {
            foreach ($action instanceof ActionGroup ? $action->getFlatActions() : [$action] as $flat) {
                $actions[$flat->getName()] = $flat;
            }
        }

        foreach ($this->getSchemasWithActions() as $schema) {
            foreach ($schema->getFlatComponents(withHidden: true) as $component) {
                // Sections (header/footer actions) and components with `HasEmbeddedActions` (Callout, EmptyState).
                if (method_exists($component, 'getAllActions') && ! $component->isHiddenInTree()) {
                    foreach ($component->getAllActions() as $action) {
                        $actions[$action->getName()] = $this->prepareAction($action);
                    }
                }
            }
        }

        return $this->cachedActions = $actions;
    }

    /**
     * Schemas whose embedded actions (section header/footer) should be resolvable.
     *
     * @return array<Schema>
     */
    protected function getSchemasWithActions(): array
    {
        $schemas = [];

        if (method_exists($this, 'getForm') && ($form = $this->getForm()) !== null) {
            $schemas[] = $form;
        }

        if (method_exists($this, 'getInfolist') && ($infolist = $this->getInfolist()) !== null) {
            $schemas[] = $infolist;
        }

        // Custom page content (callouts and empty states with actions).
        if (method_exists($this, 'getContentSchema') && ($content = $this->getContentSchema()) !== null) {
            $schemas[] = $content;
        }

        // Named forms and infolists of custom pages (`customerForm()`…).
        if (method_exists($this, 'getSchemaBlockSchemas')) {
            $schemas = [...$schemas, ...$this->getSchemaBlockSchemas()];
        }

        return $schemas;
    }

    public function getAction(string $name): ?Action
    {
        return $this->getActions()[$name] ?? null;
    }

    /**
     * Resolve an action from a context component identifier (`null`/`page`, `table`,
     * `relationManager:Class`, `widget:Class`) and name.
     */
    public function resolveAction(?string $component, string $name, bool $isBulk = false): Action
    {
        $component ??= 'page';

        // `parent>child`: an action inside another action's modal.
        if (str_contains($name, Action::PATH_SEPARATOR)) {
            $path = explode(Action::PATH_SEPARATOR, $name);
            $root = (string) array_shift($path);
            $action = $this->resolveAction($component, $root, $isBulk)->getNestedModalAction($path);

            return $action === null
                ? throw new ActionNotResolvableException("Modal action [{$name}] could not be resolved on [".static::class.'].')
                : $this->applyPageDatabaseTransactions($action);
        }

        if ($component === 'page' || str_starts_with($component, 'action:')) {
            $action = $this->getAction($name);
        } elseif ($component === 'table' && method_exists($this, 'getTable')) {
            $table = $this->getTable();
            $action = $isBulk ? $table?->getBulkAction($name) : $table?->getAction($name);
        } elseif (str_starts_with($component, 'table:') && method_exists($this, 'getNamedTable')) {
            $table = $this->getNamedTable(substr($component, strlen('table:')));
            $action = $isBulk ? $table?->getBulkAction($name) : $table?->getAction($name);
        } elseif (str_starts_with($component, 'relationManager:') && method_exists($this, 'getRelationManager')) {
            $manager = $this->getRelationManager(substr($component, strlen('relationManager:')));
            $action = $isBulk ? $manager?->getTable()->getBulkAction($name) : $manager?->getTable()->getAction($name);
        } elseif (str_starts_with($component, 'widget:') && method_exists($this, 'getWidgetInstance')) {
            $widget = $this->getWidgetInstance(substr($component, strlen('widget:')));
            $table = $widget !== null && method_exists($widget, 'getTable') ? $widget->getTable() : null;
            $action = $isBulk ? $table?->getBulkAction($name) : $table?->getAction($name);

            // Actions of the widget itself (a chart's `getEmptyStateActions()`).
            if ($action === null && ! $isBulk && $widget !== null && method_exists($widget, 'getAction')) {
                $action = $widget->getAction($name);
            }
        } else {
            $action = null;
        }

        if ($action === null) {
            throw new ActionNotResolvableException("Action [{$name}] could not be resolved on [".static::class."] for component [{$component}].");
        }

        if ($isBulk && ! $action instanceof BulkAction) {
            throw new ActionNotResolvableException("Action [{$name}] is not a bulk action.");
        }

        return $this->applyPageDatabaseTransactions($action);
    }

    /**
     * The page's `$hasDatabaseTransactions` for actions that don't choose themselves
     * (`databaseTransaction()`); null pages follow the panel.
     *
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @return TAction
     */
    protected function applyPageDatabaseTransactions(Action $action): Action
    {
        $page = $this->hasDatabaseTransactions();

        if ($page === null) {
            return $action;
        }

        /** @var bool|Closure|null $own */
        $own = (fn (): mixed => $this->hasDatabaseTransaction)->call($action);

        if ($own === null) {
            $action->databaseTransaction($page);
        }

        return $action;
    }

    /**
     * @template T of Action|ActionGroup
     *
     * @param  array<T>  $actions
     * @return array<T>
     */
    protected function prepareActions(array $actions): array
    {
        foreach ($actions as $action) {
            $this->prepareAction($action);
        }

        return $actions;
    }

    /**
     * @template T of Action|ActionGroup
     *
     * @param  T  $action
     * @return T
     */
    protected function prepareAction(Action|ActionGroup $action): Action|ActionGroup
    {
        $action->context($this);

        if ($action instanceof Action) {
            if (method_exists($this, 'getRecord') && ($record = $this->getRecord()) !== null) {
                $action->record($record);
            } elseif (method_exists($this, 'getModel') && ($model = $this->getModel()) !== null) {
                $action->model($model);
            }

            if ($this instanceof \AlfaSix\Alfakit\Contracts\HasResource && ($resource = $this->getResourceClass()) !== null) {
                $action->recordTitle(fn (?\Illuminate\Database\Eloquent\Model $record): ?string => $record !== null ? $resource::getRecordTitle($record) : null);
            }
        } else {
            if (method_exists($this, 'getRecord') && ($record = $this->getRecord()) !== null) {
                $action->record($record);
            }
        }

        return $action;
    }

    /**
     * @param  array<Action|ActionGroup>  $actions
     * @return array<int, array<string, mixed>>
     */
    protected function serializeActions(array $actions): array
    {
        return collect($actions)
            ->filter(static fn (Action|ActionGroup $action): bool => $action->isVisible())
            ->map(static fn (Action|ActionGroup $action): array => $action->toSchema())
            ->values()
            ->all();
    }
}
