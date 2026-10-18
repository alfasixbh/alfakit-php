<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing\Concerns;

use Closure;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert;

/**
 * Schema components by key (sections, tabs, wizard steps, fields, entries), infolist
 * state, and wizards. `$schema` is `form` (the page form, or the mounted action's form),
 * `infolist`, `content`, or the key of any content block (`form:customer`).
 */
trait TestsSchemas
{
    /** @var array<string, int> The current wizard step (1-based), per form (`form` or the mounted action) */
    protected array $wizardSteps = [];

    /**
     * The page renders this schema: `form` (the page form, or the mounted action's form),
     * `infolist`, `content`, or a content block's key (`form:customer`).
     */
    public function assertSchemaExists(string $schema = 'form'): static
    {
        $exists = match (true) {
            $schema === 'form' && $this->mountedAction !== null => (bool) $this->mountedAction['hasForm'],
            $schema === 'form' => $this->getContentBlock('form') !== null,
            default => $this->getSchemaComponents($schema) !== [],
        };

        Assert::assertTrue($exists, "Failed asserting that the page has the [{$schema}] schema.");

        return $this;
    }

    /**
     * Run an action of a field (`prefixActions()`, `suffixActions()`, `hintActions()`, a
     * Select's create option action…) of the page form, or of the mounted action's form,
     * as clicking it does. Its modal is opened first when it has one; `$data` fills the
     * modal form. The owning form takes the state the action left (`$set()`).
     *
     * @param  string  $component  The field's state path (or key)
     * @param  string|array<int, string>  $name
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    public function callFormComponentAction(string $component, string|array $name, array $data = [], array $arguments = [], string $schema = 'form'): static
    {
        $name = $this->resolveActionName($name);
        $schemaName = $schema === 'form' && $this->mountedAction !== null
            ? 'action:'.$this->mountedAction['component'].'.'.$this->mountedAction['name']
            : $schema;
        $reference = 'field:'.$schemaName.'::'.$component;
        $field = $this->findSchemaComponent($component, $schema) ?? $this->findInTree($this->getSchemaComponents($schema), static fn (array $node): bool => isset($node['type']) && ($node['statePath'] ?? $node['name'] ?? null) === $component);

        Assert::assertNotNull($field, "Failed asserting that the [{$schema}] schema has a field [{$component}].");

        $action = $this->findActionInTree($field, explode(\AlfaSix\Alfakit\Actions\Action::PATH_SEPARATOR, $name)[0]);

        Assert::assertNotNull($action, "Failed asserting that the field [{$component}] has a visible action [{$name}].");

        $mounted = $this->mountedAction;
        $state = $schema === 'form' ? $this->getFormState() : null;
        $modalData = [];

        if ($action['hasModal'] ?? false) {
            $modal = $this->postJson('modal', '/_alfakit/actions/modal', array_filter([
                'token' => $this->getToken(),
                'component' => $reference,
                'action' => $name,
                'record' => $mounted['record'] ?? null,
                'arguments' => $arguments ?: null,
                'state' => $state,
            ], static fn (mixed $value): bool => $value !== null));

            Assert::assertSame(200, $modal->getStatusCode(), "Opening the field action [{$name}] failed with status {$modal->getStatusCode()}.");

            if ($modal->json('cancelled') === true) {
                return $this;
            }

            $modalData = (array) ($modal->json('form.state') ?? []);
        }

        foreach ($data as $path => $value) {
            Arr::set($modalData, (string) $path, $value);
        }

        $this->forgetFlash();
        $this->lastActionName = $name;

        $response = $this->postJson('action', '/_alfakit/actions', array_filter([
            'token' => $this->getToken(),
            'component' => $reference,
            'action' => $name,
            'record' => $mounted['record'] ?? null,
            'arguments' => $arguments ?: null,
            'data' => $modalData,
            'state' => $state,
        ], static fn (mixed $value): bool => $value !== null));

        $status = $response->getStatusCode();
        $this->errors = $status === 422 ? $this->normalizeErrors((array) $response->json('errors')) : [];

        foreach ((array) $response->json('notifications') as $notification) {
            if (is_array($notification)) {
                $this->notifications[] = $notification;
            }
        }

        $this->actionResult = is_string($response->json('status')) ? ['name' => $name, 'status' => $response->json('status')] : null;

        $form = $response->json('form');

        if ($schema === 'form' && is_array($form) && is_array($form['state'] ?? null)) {
            $this->setFormState($form['state']);

            if (is_array($form['components'] ?? null)) {
                $this->setFormComponents($form['components']);
            }
        }

        return $this;
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $checkComponentUsing  Receives the component's schema
     */
    public function assertSchemaComponentExists(string $key, string $schema = 'form', ?Closure $checkComponentUsing = null): static
    {
        $schema = $this->resolveDefaultSchemaName($schema);
        $component = $this->findSchemaComponent($key, $schema);

        Assert::assertNotNull($component, "Failed asserting that the [{$schema}] schema has a component with key [{$key}].");

        if ($checkComponentUsing !== null) {
            Assert::assertTrue((bool) $checkComponentUsing($component), "Failed asserting that the [{$key}] component of the [{$schema}] schema passes the check.");
        }

        return $this;
    }

    public function assertSchemaComponentDoesNotExist(string $key, string $schema = 'form'): static
    {
        $schema = $this->resolveDefaultSchemaName($schema);

        Assert::assertNull($this->findSchemaComponent($key, $schema), "Failed asserting that the [{$schema}] schema has no component with key [{$key}].");

        return $this;
    }

    /**
     * Components are sent to the browser only while visible, so a visible component is
     * one that exists.
     */
    public function assertSchemaComponentVisible(string $key, string $schema = 'form'): static
    {
        return $this->assertSchemaComponentExists($key, $schema);
    }

    public function assertSchemaComponentHidden(string $key, string $schema = 'form'): static
    {
        return $this->assertSchemaComponentDoesNotExist($key, $schema);
    }

    /**
     * Assert state by path. For `form` this is `assertFormSet()`; for an infolist, each key
     * is an entry name and is compared with the state the entry shows (formatted). On a
     * page without a form (a View page) the default `form` means the infolist.
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>): (array<string, mixed>|null|void)  $state
     */
    public function assertSchemaStateSet(array|Closure $state, string $schema = 'form'): static
    {
        $schema = $this->resolveDefaultSchemaName($schema);

        if ($schema === 'form') {
            return $this->assertFormSet($state);
        }

        $current = $this->getSchemaEntryState($schema);

        if ($state instanceof Closure) {
            $state = $state($current);

            if (! is_array($state)) {
                return $this;
            }
        }

        foreach ($state as $name => $expected) {
            Assert::assertArrayHasKey((string) $name, $current, "Failed asserting that the [{$schema}] schema shows an entry [{$name}].");
            Assert::assertEquals($expected, $current[(string) $name], "Failed asserting the state of the [{$name}] entry of the [{$schema}] schema.");
        }

        return $this;
    }

    /**
     * The state each entry of a schema shows, by entry name.
     *
     * @return array<string, mixed>
     */
    public function getSchemaEntryState(string $schema = 'infolist'): array
    {
        $state = [];

        $collect = function (array $tree) use (&$collect, &$state): void {
            foreach ($tree as $key => $node) {
                if (! is_array($node) || $key === 'template') {
                    continue;
                }

                if (isset($node['type'], $node['name']) && is_string($node['name']) && array_key_exists('state', $node) && ! isset($node['statePath'])) {
                    $state[$node['name']] ??= $node['state'];
                }

                $collect($node);
            }
        };

        $collect($this->getSchemaComponents($schema));

        return $state;
    }

    // ---------------------------------------------------------------- Wizards

    /**
     * Validate the current step on the server (as "Next" does) and move on when it passes.
     * Validation errors are kept for `assertHasFormErrors()`.
     */
    public function goToNextWizardStep(): static
    {
        $steps = $this->getWizardSteps();
        $current = $this->getWizardStep();
        $step = $steps[$current - 1] ?? null;

        Assert::assertIsArray($step, 'The form has no wizard.');

        if ($this->validateWizardStep((string) ($step['key'] ?? '')) && $current < count($steps)) {
            $this->wizardSteps[$this->wizardContext()] = $current + 1;
        }

        return $this;
    }

    public function goToPreviousWizardStep(): static
    {
        $this->wizardSteps[$this->wizardContext()] = max(1, $this->getWizardStep() - 1);

        return $this;
    }

    /**
     * Go to a step (1-based). Moving forward validates each step on the way, like a user
     * clicking "Next"; it stops at the first step that doesn't pass.
     */
    public function goToWizardStep(int $step): static
    {
        $count = count($this->getWizardSteps());

        Assert::assertGreaterThan(0, $count, 'The form has no wizard.');
        Assert::assertTrue($step >= 1 && $step <= $count, "The wizard has no step {$step} (it has {$count}).");

        while ($this->getWizardStep() < $step) {
            $before = $this->getWizardStep();
            $this->goToNextWizardStep();

            if ($this->getWizardStep() === $before) {
                break;
            }
        }

        if ($step < $this->getWizardStep()) {
            $this->wizardSteps[$this->wizardContext()] = $step;
        }

        return $this;
    }

    public function assertWizardCurrentStep(int $step): static
    {
        Assert::assertSame($step, $this->getWizardStep(), "Failed asserting that the wizard is on step {$step}.");

        return $this;
    }

    public function assertWizardStepExists(int $step): static
    {
        Assert::assertArrayHasKey($step - 1, $this->getWizardSteps(), "Failed asserting that the wizard has step {$step}.");

        return $this;
    }

    public function getWizardStep(): int
    {
        if (isset($this->wizardSteps[$this->wizardContext()])) {
            return $this->wizardSteps[$this->wizardContext()];
        }

        $wizard = $this->findComponentOfType($this->getSchemaComponents('form'), 'Wizard');
        $start = is_numeric($wizard['startStep'] ?? null) ? (int) $wizard['startStep'] : 1;

        return max(1, min($start, max(1, count($this->getWizardSteps()))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getWizardSteps(): array
    {
        $wizard = $this->findComponentOfType($this->getSchemaComponents('form'), 'Wizard');

        return array_values(array_filter((array) ($wizard['schema'] ?? []), is_array(...)));
    }

    protected function validateWizardStep(string $key): bool
    {
        $mounted = $this->mountedAction;

        $response = $this->postJson('schemaValidate', '/_alfakit/schema/validate', array_filter([
            'token' => $this->getToken(),
            'component' => $mounted === null ? 'form' : 'action:'.$mounted['component'].'.'.$mounted['name'],
            'record' => $mounted['record'] ?? null,
            'arguments' => $mounted['arguments'] ?? null,
            'state' => $this->getFormState(),
            'step' => $key,
        ], static fn (mixed $value): bool => $value !== null));

        $status = $response->getStatusCode();

        if ($status === 422) {
            $this->errors = $this->normalizeErrors((array) $response->json('errors'));

            return false;
        }

        Assert::assertLessThan(400, $status, "Validating wizard step [{$key}] failed with status {$status}.");

        $this->errors = [];

        if ($status === 204 || $response->getContent() === '') {
            return true;
        }

        foreach ((array) $response->json('notifications') as $notification) {
            if (is_array($notification)) {
                $this->notifications[] = $notification;
            }
        }

        // A step hook that halted keeps the wizard where it is.
        return $response->json('halted') !== true;
    }

    // ---------------------------------------------------------------- Internals

    protected function wizardContext(): string
    {
        return $this->mountedAction === null ? 'form' : 'action:'.$this->mountedAction['component'].'.'.$this->mountedAction['name'];
    }

    /**
     * @return array<mixed>
     */
    /**
     * On a page without a form (a View page), the default `form` schema means the page's
     * infolist, as in Filament where the view page's schema is its infolist.
     */
    protected function resolveDefaultSchemaName(string $schema): string
    {
        if ($schema !== 'form' || $this->mountedAction !== null || $this->formComponents !== [] || $this->getContentBlock('form') !== null) {
            return $schema;
        }

        return $this->getSchemaComponents('infolist') !== [] ? 'infolist' : $schema;
    }

    protected function getSchemaComponents(string $schema): array
    {
        if ($schema === 'form') {
            return $this->mountedAction !== null ? (array) $this->mountedAction['components'] : $this->formComponents;
        }

        $blocks = [];

        foreach ((array) ($this->getPage()['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            $key = (string) ($block['key'] ?? '');

            $matches = match ($schema) {
                'infolist' => $type === 'Infolist' && ($key === 'infolist' || str_starts_with($key, 'infolist')),
                'content' => $type === 'Schema',
                default => $key === $schema || ($block['component'] ?? null) === $schema,
            };

            if ($matches) {
                $blocks[] = $block['components'] ?? [];
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findSchemaComponent(string $key, string $schema): ?array
    {
        return $this->findInTree($this->getSchemaComponents($schema), static fn (array $node): bool => isset($node['type']) && (($node['key'] ?? null) === $key || ($node['id'] ?? null) === $key));
    }

    /**
     * @param  array<mixed>  $tree
     * @return array<string, mixed>|null
     */
    protected function findComponentOfType(array $tree, string $type): ?array
    {
        return $this->findInTree($tree, static fn (array $node): bool => ($node['type'] ?? null) === $type);
    }

    /**
     * @param  array<mixed>  $tree
     * @param  Closure(array<mixed>): bool  $matches
     * @return array<string, mixed>|null
     */
    protected function findInTree(array $tree, Closure $matches): ?array
    {
        foreach ($tree as $key => $node) {
            // Repeater templates describe items that don't exist yet.
            if ($key === 'template' || ! is_array($node)) {
                continue;
            }

            if ($matches($node)) {
                return $node;
            }

            if (($found = $this->findInTree($node, $matches)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
