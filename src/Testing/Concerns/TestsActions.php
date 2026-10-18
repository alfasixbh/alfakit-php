<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use Throwable;

/**
 * Page actions (header actions, form actions and actions inside the page's schemas):
 * open their modal, fill its form and run them through the action endpoints.
 *
 * Action names may be a name (`publish`), an action class with a default name
 * (`DeleteAction::class`), or a path to an action inside another action's modal
 * (`['publish', 'confirm']` or `'publish>confirm'`).
 */
trait TestsActions
{
    /**
     * The mounted (open) action modal: owner component, name, record, arguments, modal
     * schema and form state.
     *
     * @var array{component: string, name: string, record: int|string|null, records: array<int, int|string>|null, allRecords: bool, arguments: array<string, mixed>|null, modal: array<string, mixed>, data: array<string, mixed>, components: array<int, array<string, mixed>>, hasForm: bool}|null
     */
    protected ?array $mountedAction = null;

    /**
     * Mounted actions under the current one (a nested action's parents), outermost first.
     * Their form state is sent with the nested action, and they are mounted again when it
     * closes, like the stacked modals in the browser.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $parentMountedActions = [];

    /**
     * Open an action's modal, as clicking its button does: its form is filled from the
     * server (`fillForm()`, defaults) and `fillForm()` now targets it.
     *
     * @param  string|class-string<Action>|array<int, string>  $name
     * @param  array<string, mixed>  $arguments
     */
    public function mountAction(string|array $name, array $arguments = []): static
    {
        return $this->mountActionFor('page', $this->resolveActionName($name), null, null, false, $arguments);
    }

    /**
     * Run a page action: modal actions are mounted first (so their form is filled from
     * the server), then `$data` is filled in and the action is submitted.
     *
     * @param  string|class-string<Action>|array<int, string>  $name
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    public function callAction(string|array $name, array $data = [], array $arguments = []): static
    {
        $name = $this->resolveActionName($name);
        $schema = $this->findMountedChildAction($name) ?? $this->findPageAction($name);

        if ($schema !== null && ! ($schema['hasModal'] ?? false) && $data === []) {
            return $this->runAction('page', $name, null, null, false, $arguments, []);
        }

        $this->mountActionFor('page', $name, null, null, false, $arguments);

        if ($this->mountedAction === null) {
            return $this;
        }

        return $this->fillForm($data)->callMountedAction();
    }

    /**
     * Fill the mounted action's form (an alias of `fillForm()` while it is mounted).
     *
     * @param  array<string, mixed>  $data
     */
    public function setActionData(array $data): static
    {
        Assert::assertNotNull($this->mountedAction, 'No action is mounted. Call mountAction() first.');

        return $this->fillForm($data);
    }

    /**
     * Submit the mounted action with its form state. On success the modal closes; with
     * validation errors it stays open so `assertHasActionErrors()` can inspect them.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function callMountedAction(array $arguments = []): static
    {
        Assert::assertNotNull($this->mountedAction, 'No action is mounted. Call mountAction() first.');

        $mounted = $this->mountedAction;

        return $this->runAction(
            $mounted['component'],
            $mounted['name'],
            $mounted['record'],
            $mounted['records'],
            $mounted['allRecords'],
            [...($mounted['arguments'] ?? []), ...$arguments],
            $mounted['data'],
        );
    }

    /**
     * Close the mounted modal without running the action.
     */
    public function unmountAction(): static
    {
        $this->mountedAction = array_pop($this->parentMountedActions);

        return $this;
    }

    public function assertActionMounted(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertSame($name, $this->mountedAction['name'] ?? null, "Failed asserting that the action [{$name}] is mounted.");

        return $this;
    }

    public function assertActionNotMounted(string|array|null $name = null): static
    {
        if ($name === null) {
            Assert::assertNull($this->mountedAction, 'Failed asserting that no action is mounted.');
        } else {
            Assert::assertNotSame($this->resolveActionName($name), $this->mountedAction['name'] ?? null);
        }

        return $this;
    }

    /**
     * The serialised modal of the mounted action (heading, description, form…).
     *
     * @return array<string, mixed>|null
     */
    public function getMountedActionModal(): ?array
    {
        return $this->mountedAction['modal'] ?? null;
    }

    /**
     * The mounted modal shows this text (or each of these texts), escaped like the
     * renderer shows plain text.
     *
     * @param  string|array<int, string>  $text
     */
    public function assertMountedActionModalSee(string|array $text): static
    {
        foreach ((array) $text as $value) {
            Assert::assertTrue($this->mountedModalContains(e($value)) || $this->mountedModalContains($value), "Failed asserting that the mounted action modal shows [{$value}].");
        }

        return $this;
    }

    /**
     * @param  string|array<int, string>  $text
     */
    public function assertMountedActionModalDontSee(string|array $text): static
    {
        foreach ((array) $text as $value) {
            Assert::assertFalse($this->mountedModalContains(e($value)) || $this->mountedModalContains($value), "Failed asserting that the mounted action modal does not show [{$value}].");
        }

        return $this;
    }

    /**
     * The mounted modal contains this HTML as is (descriptions, HTML content).
     *
     * @param  string|array<int, string>  $html
     */
    public function assertMountedActionModalSeeHtml(string|array $html): static
    {
        foreach ((array) $html as $value) {
            Assert::assertTrue($this->mountedModalContains($value), "Failed asserting that the mounted action modal contains the HTML [{$value}].");
        }

        return $this;
    }

    /**
     * @param  string|array<int, string>  $html
     */
    public function assertMountedActionModalDontSeeHtml(string|array $html): static
    {
        foreach ((array) $html as $value) {
            Assert::assertFalse($this->mountedModalContains($value), "Failed asserting that the mounted action modal does not contain the HTML [{$value}].");
        }

        return $this;
    }

    /**
     * Whether any text of the mounted modal (heading, description, content, form…)
     * contains `$needle`.
     */
    protected function mountedModalContains(string $needle): bool
    {
        Assert::assertNotNull($this->mountedAction, 'No action is mounted.');

        $found = false;

        array_walk_recursive($this->mountedAction['modal'], static function (mixed $value) use ($needle, &$found): void {
            if (! $found && is_string($value) && str_contains($value, $needle)) {
                $found = true;
            }
        });

        return $found;
    }

    /**
     * The action ran but stopped with `$action->halt()`.
     */
    public function assertActionHalted(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertSame(['name' => $name, 'status' => 'halted'], Arr::only($this->actionResult ?? [], ['name', 'status']), "Failed asserting that the action [{$name}] was halted.");

        return $this;
    }

    /**
     * The action ran to completion (no validation errors, not halted or cancelled).
     */
    public function assertActionSucceeded(string|array|null $name = null): static
    {
        $this->assertLastActionDidNotFail();

        Assert::assertSame([], $this->errors, 'The action has validation errors: '.json_encode($this->errors));

        if (($this->actionResult['name'] ?? null) !== null || $name !== null) {
            Assert::assertSame('success', $this->actionResult['status'] ?? null, 'Failed asserting that the action succeeded.');
        }

        if ($name !== null) {
            Assert::assertSame($this->resolveActionName($name), $this->actionResult['name'] ?? null);
        }

        return $this;
    }

    /**
     * @param  array<int|string, string|array<int, string>>  $keys
     */
    public function assertHasActionErrors(array $keys = []): static
    {
        return $this->assertHasFormErrors($keys);
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function assertHasNoActionErrors(array $keys = []): static
    {
        return $this->assertHasNoFormErrors($keys);
    }

    // ---------------------------------------------------------------- Visibility

    /**
     * Visible page actions are the ones the server serialises (header, form and schema
     * actions); hidden or unauthorised ones are never sent to the browser.
     */
    public function assertActionVisible(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNotNull($this->findPageAction($name), "Failed asserting that the page action [{$name}] is visible.");

        return $this;
    }

    public function assertActionExists(string|array $name): static
    {
        return $this->assertActionVisible($name);
    }

    public function assertActionHidden(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNull($this->findPageAction($name), "Failed asserting that the page action [{$name}] is hidden.");

        return $this;
    }

    public function assertActionDoesNotExist(string|array $name): static
    {
        return $this->assertActionHidden($name);
    }

    public function assertActionEnabled(string|array $name): static
    {
        $name = $this->resolveActionName($name);
        $action = $this->findPageAction($name);

        Assert::assertNotNull($action, "Failed asserting that the page action [{$name}] is visible.");
        Assert::assertFalse((bool) ($action['disabled'] ?? false), "Failed asserting that the page action [{$name}] is enabled.");

        return $this;
    }

    public function assertActionDisabled(string|array $name): static
    {
        $name = $this->resolveActionName($name);
        $action = $this->findPageAction($name);

        Assert::assertNotNull($action, "Failed asserting that the page action [{$name}] is visible.");
        Assert::assertTrue((bool) ($action['disabled'] ?? false), "Failed asserting that the page action [{$name}] is disabled.");

        return $this;
    }

    public function assertActionHasLabel(string|array $name, string $label): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertSame($label, $this->findPageAction($name)['label'] ?? null);

        return $this;
    }

    public function assertActionDoesNotHaveLabel(string|array $name, string $label): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNotSame($label, $this->requirePageAction($name)['label'] ?? null, "Failed asserting that the page action [{$name}] does not have the label [{$label}].");

        return $this;
    }

    public function assertActionHasUrl(string|array $name, string $url): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertSame($url, $this->findPageAction($name)['url'] ?? null);

        return $this;
    }

    public function assertActionDoesNotHaveUrl(string|array $name, string $url): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNotSame($url, $this->requirePageAction($name)['url'] ?? null, "Failed asserting that the page action [{$name}] does not link to [{$url}].");

        return $this;
    }

    /**
     * The action's icon, by name or registered alias (`alfakit::actions.delete`).
     */
    public function assertActionHasIcon(string|array $name, string $icon): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertContains($this->requirePageAction($name)['icon'] ?? null, $this->iconCandidates($icon), "Failed asserting that the page action [{$name}] has the icon [{$icon}].");

        return $this;
    }

    public function assertActionDoesNotHaveIcon(string|array $name, string $icon): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNotContains($this->requirePageAction($name)['icon'] ?? null, $this->iconCandidates($icon), "Failed asserting that the page action [{$name}] does not have the icon [{$icon}].");

        return $this;
    }

    public function assertActionHasColor(string|array $name, string|\AlfaSix\Alfakit\Support\Enums\Color $color): static
    {
        $name = $this->resolveActionName($name);
        $color = $color instanceof \AlfaSix\Alfakit\Support\Enums\Color ? $color->value : $color;

        Assert::assertSame($color, $this->requirePageAction($name)['color'] ?? null, "Failed asserting that the page action [{$name}] has the color [{$color}].");

        return $this;
    }

    public function assertActionDoesNotHaveColor(string|array $name, string|\AlfaSix\Alfakit\Support\Enums\Color $color): static
    {
        $name = $this->resolveActionName($name);
        $color = $color instanceof \AlfaSix\Alfakit\Support\Enums\Color ? $color->value : $color;

        Assert::assertNotSame($color, $this->requirePageAction($name)['color'] ?? null, "Failed asserting that the page action [{$name}] does not have the color [{$color}].");

        return $this;
    }

    public function assertActionShouldOpenUrlInNewTab(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertTrue((bool) ($this->requirePageAction($name)['openUrlInNewTab'] ?? false), "Failed asserting that the page action [{$name}] opens its URL in a new tab.");

        return $this;
    }

    public function assertActionShouldNotOpenUrlInNewTab(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertFalse((bool) ($this->requirePageAction($name)['openUrlInNewTab'] ?? false), "Failed asserting that the page action [{$name}] does not open its URL in a new tab.");

        return $this;
    }

    /**
     * The page actions with these names are visible and shown in this order (other
     * actions may sit between them).
     *
     * @param  array<int, string|class-string<Action>>  $names
     */
    public function assertActionListInOrder(array $names): static
    {
        $order = $this->getPageActionNames();
        $positions = [];

        foreach ($names as $name) {
            $name = $this->resolveActionName($name);
            $position = array_search($name, $order, true);

            Assert::assertNotFalse($position, "Failed asserting that the page action [{$name}] is visible.");

            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);

        Assert::assertSame($sorted, $positions, 'Failed asserting that the page actions are in the order ['.implode(', ', $names).']. Actual order: ['.implode(', ', $order).'].');

        return $this;
    }

    /**
     * The names of the visible page actions, in the order they are shown (header actions,
     * then actions in the page's schemas; inside groups too).
     *
     * @return array<int, string>
     */
    public function getPageActionNames(): array
    {
        $names = [];

        foreach ($this->getPageActionTrees() as $tree) {
            $this->collectActionNames($tree, $names);
        }

        return array_values(array_unique($names));
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @param  array<int, int|string>|null  $records
     * @param  array<string, mixed>  $arguments
     */
    protected function mountActionFor(string $component, string $name, int|string|null $record, ?array $records, bool $allRecords, array $arguments): static
    {
        $this->stackParentActions($component, $name);
        $this->mountedAction = null;
        $this->lastActionName = $name;

        $response = $this->getJson('modal', '/_alfakit/actions/modal', [
            'token' => $this->getToken(),
            'component' => $component,
            'action' => $name,
            'record' => $record,
            'records' => $records,
            'bulk' => $records !== null || $allRecords ? 1 : null,
            'allRecords' => $allRecords ? 1 : null,
            'arguments' => $arguments ?: null,
            'tableQuery' => http_build_query($this->tableState),
            'mountedData' => $this->getParentMountedData() ?: null,
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->mountedAction = array_pop($this->parentMountedActions);

            return $this;
        }

        $modal = (array) $response->json();
        $form = is_array($modal['form'] ?? null) ? $modal['form'] : null;

        $this->mountedAction = [
            'component' => $component,
            'name' => $name,
            'record' => $record,
            'records' => $records,
            'allRecords' => $allRecords,
            'arguments' => $arguments ?: null,
            'modal' => $modal,
            'data' => is_array($form['state'] ?? null) ? $form['state'] : [],
            'components' => is_array($form['components'] ?? null) ? $form['components'] : [],
            'hasForm' => $form !== null,
        ];

        return $this;
    }

    /**
     * @param  array<int, int|string>|null  $records
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    protected function runAction(string $component, string $name, int|string|null $record, ?array $records, bool $allRecords, array $arguments, array $data): static
    {
        // A nested action without a modal runs from its open parent.
        if ($this->mountedAction === null || $this->mountedAction['name'] !== $name) {
            $this->stackParentActions($component, $name);
        }

        $this->postAction([
            'mountedData' => $this->getParentMountedData() ?: null,
            'component' => $component,
            'action' => $name,
            'record' => $record,
            'records' => $records,
            'bulk' => $records !== null || $allRecords ? 1 : null,
            'allRecords' => $allRecords ? 1 : null,
            'arguments' => $arguments ?: null,
            'data' => $data,
        ]);

        $status = $this->lastResponse->getStatusCode();

        if ($status === 422) {
            // A nested action without its own modal: its parent's modal shows the errors.
            if ($this->mountedAction === null || $this->mountedAction['name'] !== $name) {
                $this->mountedAction = array_pop($this->parentMountedActions) ?? $this->mountedAction;
            }

            return $this;
        }

        $result = $this->actionResult ?? [];
        $isRunning = $this->mountedAction !== null && $this->mountedAction['name'] === $name;

        // `fillData()`: values the nested action wrote into its parents' forms.
        foreach ((array) ($result['parentData'] ?? []) as $filled) {
            if (is_array($filled)) {
                $this->fillParentData((string) ($filled['name'] ?? ''), (array) ($filled['data'] ?? []));
            }
        }

        // The modal closes after a successful run (unless the action keeps it open).
        if (! $isRunning && $this->mountedAction === null) {
            // A nested action without a modal: its parent stays open.
            $this->mountedAction = array_pop($this->parentMountedActions);
        }

        $succeeded = $status < 400 && ($result['status'] ?? null) === 'success';

        if ($status < 400 && $isRunning && ($result['closeModal'] ?? true) !== false && ($result['status'] ?? null) !== 'halted') {
            $this->mountedAction = array_pop($this->parentMountedActions);
        }

        if ($succeeded) {
            // `cancelParentActions()`: every parent, or the named one and those inside it.
            $cancel = $result['cancelParentActions'] ?? null;

            if ($cancel !== null) {
                $this->cancelParentMountedActions($cancel);
            }
        }

        if ($status < 400) {
            $this->reloadAfterAction();
        }

        // `replaceMountedAction()`: the next action opens (or runs) in its place.
        $replacement = $result['replaceMountedAction'] ?? null;

        if ($status < 400 && is_array($replacement) && is_string($replacement['name'] ?? null)) {
            $path = implode(Action::PATH_SEPARATOR, [...array_slice(explode(Action::PATH_SEPARATOR, $name), 0, -1), $replacement['name']]);
            $replacementArguments = (array) ($replacement['arguments'] ?? []);

            if ($replacement['hasModal'] ?? false) {
                return $this->mountActionFor($component, $path, $record, $records, $allRecords, $replacementArguments);
            }

            return $this->runAction($component, $path, $record, $records, $allRecords, $replacementArguments, []);
        }

        return $this;
    }

    /**
     * Mounting `parent>child` while `parent` is mounted keeps the parent underneath.
     */
    protected function stackParentActions(string $component, string $name): void
    {
        $mounted = $this->mountedAction;

        if ($mounted !== null && $mounted['component'] === $component && str_starts_with($name, $mounted['name'].Action::PATH_SEPARATOR)) {
            $this->parentMountedActions[] = $mounted;
            $this->mountedAction = null;

            return;
        }

        if ($mounted === null || $mounted['name'] !== $name) {
            // Not nested in what is open: the stack starts again.
            $this->parentMountedActions = array_values(array_filter(
                $this->parentMountedActions,
                static fn (array $parent): bool => $parent['component'] === $component && str_starts_with($name, $parent['name'].Action::PATH_SEPARATOR),
            ));
        }
    }

    /**
     * The form state of the mounted parents, outermost first, aligned with the nested
     * action's path.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getParentMountedData(): array
    {
        return array_values(array_map(static fn (array $parent): array => (array) ($parent['data'] ?? []), $this->parentMountedActions));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fillParentData(string $name, array $data): void
    {
        $update = static function (array $action) use ($name, $data): array {
            $segments = explode(Action::PATH_SEPARATOR, (string) $action['name']);

            if (end($segments) === $name) {
                foreach ($data as $path => $value) {
                    Arr::set($action['data'], (string) $path, $value);
                }
            }

            return $action;
        };

        $this->parentMountedActions = array_map($update, $this->parentMountedActions);

        if ($this->mountedAction !== null) {
            $this->mountedAction = $update($this->mountedAction);
        }
    }

    protected function cancelParentMountedActions(true|string $cancel): void
    {
        if ($cancel === true) {
            $this->parentMountedActions = [];
            $this->mountedAction = null;

            return;
        }

        $remaining = [];

        foreach ([...$this->parentMountedActions, ...($this->mountedAction === null ? [] : [$this->mountedAction])] as $action) {
            $segments = explode(Action::PATH_SEPARATOR, (string) $action['name']);

            if (end($segments) === $cancel) {
                break;
            }

            $remaining[] = $action;
        }

        $this->mountedAction = array_pop($remaining);
        $this->parentMountedActions = $remaining;
    }

    /**
     * @param  string|class-string<Action>|array<int, string>  $name
     */
    protected function resolveActionName(string|array $name): string
    {
        if (is_array($name)) {
            return implode(Action::PATH_SEPARATOR, array_map(fn (string $part): string => $this->resolveActionName($part), $name));
        }

        if (! class_exists($name) || ! is_subclass_of($name, Action::class)) {
            return $name;
        }

        if (method_exists($name, 'getDefaultName')) {
            return $name::getDefaultName();
        }

        try {
            $parameter = (new ReflectionMethod($name, 'make'))->getParameters()[0] ?? null;

            if ($parameter !== null && $parameter->isDefaultValueAvailable() && is_string($default = $parameter->getDefaultValue())) {
                return $default;
            }
        } catch (Throwable) {
            // Fall through.
        }

        Assert::fail("The action [{$name}] has no default name; pass its name instead.");
    }

    /**
     * A visible page action by name. For a `parent>child` path the root action is looked
     * up: modal footer actions are only serialised when the modal opens.
     *
     * @return array<string, mixed>|null
     */
    protected function findPageAction(string $name): ?array
    {
        $root = explode(Action::PATH_SEPARATOR, $name)[0];

        foreach ($this->getPageActionTrees() as $tree) {
            if (($found = $this->findActionInTree($tree, $root)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * A footer action of the mounted modal (`parent>child` while `parent` is mounted), so
     * a child without a modal of its own runs straight from the open parent.
     *
     * @return array<string, mixed>|null
     */
    protected function findMountedChildAction(string $name): ?array
    {
        $mounted = $this->mountedAction;

        if ($mounted === null || ! str_starts_with($name, $mounted['name'].Action::PATH_SEPARATOR)) {
            return null;
        }

        $child = substr($name, strlen($mounted['name'].Action::PATH_SEPARATOR));

        return str_contains($child, Action::PATH_SEPARATOR) ? null : $this->findActionInTree((array) ($mounted['modal']['extraFooterActions'] ?? []), $child);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requirePageAction(string $name): array
    {
        $action = $this->findPageAction($name);

        Assert::assertNotNull($action, "Failed asserting that the page action [{$name}] is visible.");

        return $action;
    }

    /**
     * Where page actions live: the header actions and the page's content blocks (tables
     * and relation managers have their own actions).
     *
     * @return array<int, array<mixed>>
     */
    protected function getPageActionTrees(): array
    {
        $page = $this->getPage();
        $trees = [(array) ($page['headerActions'] ?? [])];

        foreach ((array) ($page['content'] ?? []) as $block) {
            // Table and relation manager actions belong to their tables.
            if (! is_array($block) || ($block['type'] ?? null) === 'Table' || str_starts_with((string) ($block['component'] ?? ''), 'relationManager:') || ($block['type'] ?? null) === 'RelationManagers') {
                continue;
            }

            $trees[] = $block;
        }

        return $trees;
    }

    /**
     * @param  array<mixed>  $tree
     * @param  array<int, string>  $names
     */
    protected function collectActionNames(array $tree, array &$names): void
    {
        if (in_array($tree['type'] ?? null, ['Action', 'BulkAction'], true) && is_string($tree['name'] ?? null)) {
            $names[] = $tree['name'];

            return;
        }

        foreach ($tree as $node) {
            if (is_array($node)) {
                $this->collectActionNames($node, $names);
            }
        }
    }

    /**
     * @return array<int, string|null>
     */
    protected function iconCandidates(string $icon): array
    {
        return array_values(array_unique([$icon, \AlfaSix\Alfakit\Support\Icons::resolve($icon)]));
    }

    /**
     * @param  array<mixed>  $tree
     * @return array<string, mixed>|null
     */
    protected function findActionInTree(array $tree, string $name): ?array
    {
        if (($tree['type'] ?? null) === 'Action' && ($tree['name'] ?? null) === $name) {
            return $tree;
        }

        foreach ($tree as $node) {
            if (is_array($node) && ($found = $this->findActionInTree($node, $name)) !== null) {
                return $found;
            }
        }

        return null;
    }

    protected function recordKey(Model|int|string|null $record): int|string|null
    {
        return $record instanceof Model ? $record->getKey() : $record;
    }
}
