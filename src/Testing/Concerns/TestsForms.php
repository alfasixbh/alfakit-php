<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing\Concerns;

use AlfaSix\Alfakit\Schemas\Schema;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Assert;

/**
 * Page forms: fill the state (running `afterStateUpdated` through the schema refresh
 * endpoint), submit it with a form action, and assert on state, fields and errors.
 * While an action is mounted (`mountAction()`), these work on the action's modal form.
 */
trait TestsForms
{
    /** @var array<string, mixed> State of the page form, as the renderer holds it */
    protected array $formState = [];

    /** @var array<int, array<string, mixed>> Component schemas of the page form */
    protected array $formComponents = [];

    protected function syncFormFromPage(): void
    {
        $form = $this->getContentBlock('form');

        $this->formState = is_array($form['state'] ?? null) ? $form['state'] : [];
        $this->formComponents = is_array($form['components'] ?? null) ? $form['components'] : [];
    }

    /**
     * Set form state, as typing would. Each key is a state path relative to the form
     * (`title`, `meta.seo_title`); the schema refresh endpoint runs its
     * `afterStateUpdated` hooks, so dependent fields update like in the browser.
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>): array<string, mixed>  $state
     */
    public function fillForm(array|Closure $state = []): static
    {
        $current = $this->getFormState();

        if ($state instanceof Closure) {
            $state = $state($current);
        }

        foreach ($state as $path => $value) {
            Arr::set($current, (string) $path, $value);
            $current = $this->refreshFormState($current, (string) $path);
        }

        $this->setFormState($current);

        return $this;
    }

    /**
     * The current form state, without the encrypted read-only values.
     *
     * @return array<string, mixed>
     */
    public function getFormState(): array
    {
        return $this->mountedAction !== null ? (array) $this->mountedAction['data'] : $this->formState;
    }

    /**
     * Assert form state by path. A closure receives the state and returns the expected
     * values (or asserts itself and returns nothing).
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>): (array<string, mixed>|null|void)  $state
     */
    public function assertFormSet(array|Closure $state): static
    {
        $current = $this->withoutInternalState($this->getFormState());

        if ($state instanceof Closure) {
            $state = $state($current);

            if (! is_array($state)) {
                return $this;
            }
        }

        foreach ($state as $path => $expected) {
            Assert::assertTrue(Arr::has($current, (string) $path), "Failed asserting that the form has state at [{$path}].");
            Assert::assertEquals($expected, Arr::get($current, (string) $path), "Failed asserting the form state at [{$path}].");
        }

        return $this;
    }

    /**
     * Submit the form with one of its actions (`save` on edit pages, `create` on create
     * pages, or any page action), as the form's buttons do.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $action, array $arguments = []): static
    {
        $this->postAction([
            'component' => 'page',
            'action' => $action,
            'arguments' => $arguments ?: null,
            'data' => $this->formState,
        ]);

        if ($this->errors === [] && $this->lastResponse->getStatusCode() < 400) {
            $this->reloadAfterAction();
        }

        return $this;
    }

    // ---------------------------------------------------------------- Fields

    /**
     * Fields are sent to the browser only while visible, so an existing field is a
     * visible one.
     */
    public function assertFormFieldExists(string $path, ?Closure $checkFieldUsing = null): static
    {
        $field = $this->findFormField($path);

        Assert::assertNotNull($field, "Failed asserting that the form has a visible field [{$path}].");

        if ($checkFieldUsing !== null) {
            Assert::assertTrue((bool) $checkFieldUsing($field), "Failed asserting that the field [{$path}] passes the given check.");
        }

        return $this;
    }

    public function assertFormFieldDoesNotExist(string $path): static
    {
        Assert::assertNull($this->findFormField($path), "Failed asserting that the form has no field [{$path}].");

        return $this;
    }

    public function assertFormFieldVisible(string $path): static
    {
        return $this->assertFormFieldExists($path);
    }

    /**
     * Hidden fields are neither rendered nor is their state sent to the browser.
     */
    public function assertFormFieldHidden(string $path): static
    {
        Assert::assertNull($this->findFormField($path), "Failed asserting that the field [{$path}] is hidden.");
        Assert::assertFalse(Arr::has($this->getFormState(), $path), "Failed asserting that the state of the hidden field [{$path}] is not sent to the browser.");

        return $this;
    }

    public function assertFormFieldDisabled(string $path): static
    {
        Assert::assertTrue((bool) ($this->requireFormField($path)['disabled'] ?? false), "Failed asserting that the field [{$path}] is disabled.");

        return $this;
    }

    public function assertFormFieldEnabled(string $path): static
    {
        Assert::assertFalse((bool) ($this->requireFormField($path)['disabled'] ?? false), "Failed asserting that the field [{$path}] is enabled.");

        return $this;
    }

    public function assertFormFieldReadOnly(string $path): static
    {
        Assert::assertTrue((bool) ($this->requireFormField($path)['readOnly'] ?? false), "Failed asserting that the field [{$path}] is read-only.");

        return $this;
    }

    public function assertFormFieldIsRequired(string $path): static
    {
        Assert::assertTrue((bool) ($this->requireFormField($path)['required'] ?? false), "Failed asserting that the field [{$path}] is marked as required.");

        return $this;
    }

    /**
     * The serialised schema of a visible field, by state path.
     *
     * @return array<string, mixed>|null
     */
    public function getFormField(string $path): ?array
    {
        return $this->findFormField($path);
    }

    // ---------------------------------------------------------------- Errors

    /**
     * Assert validation errors of the latest submit. Pass state paths, or `path => rule`
     * (`'title' => 'required'`, `'slug' => 'unique'`) or `path => message` pairs.
     *
     * @param  array<int|string, string|array<int, string>>  $keys
     */
    public function assertHasFormErrors(array $keys = []): static
    {
        Assert::assertSame(422, $this->lastResponse->getStatusCode(), "Expected validation errors from [{$this->describeLastRequest()}], got status {$this->lastResponse->getStatusCode()}.");

        foreach ($keys as $key => $expected) {
            if (is_int($key)) {
                $key = (string) $expected;
                $expected = [];
            }

            $path = $this->normalizeErrorPath($key);
            $messages = $this->errors[$path] ?? null;

            Assert::assertNotNull($messages, "Failed asserting that [{$path}] has a validation error. Errors: ".json_encode($this->errors));

            foreach ((array) $expected as $rule) {
                Assert::assertTrue(
                    collect($messages)->contains(fn (string $message): bool => $this->messageMatchesRule($message, (string) $rule)),
                    "Failed asserting that the [{$path}] error matches [{$rule}]. Messages: ".json_encode($messages),
                );
            }
        }

        return $this;
    }

    /**
     * Assert the latest submit had no validation errors (for the given paths, or at all).
     * The request must also have succeeded, so a 403 doesn't pass silently.
     *
     * @param  array<int, string>  $keys
     */
    public function assertHasNoFormErrors(array $keys = []): static
    {
        if ($keys === []) {
            $this->assertLastActionDidNotFail();

            Assert::assertSame([], $this->errors, 'Failed asserting that there are no validation errors. Errors: '.json_encode($this->errors));

            return $this;
        }

        foreach ($keys as $key) {
            $path = $this->normalizeErrorPath($key);

            Assert::assertArrayNotHasKey($path, $this->errors, "Failed asserting that [{$path}] has no validation error.");
        }

        return $this;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    // ---------------------------------------------------------------- Internals

    /**
     * Run `afterStateUpdated` for a path through the schema refresh endpoint and return
     * the state the server answers with.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function refreshFormState(array $state, string $path): array
    {
        $mounted = $this->mountedAction;

        // A modal without a form, or a page without one, has nothing to refresh.
        if (($mounted !== null && ! $mounted['hasForm']) || ($mounted === null && $this->getContentBlock('form') === null)) {
            return $state;
        }

        $response = $this->postJson('schemaRefresh', '/_alfakit/schema/refresh', array_filter([
            'token' => $this->getToken(),
            'component' => $mounted === null ? 'form' : 'action:'.$mounted['component'].'.'.$mounted['name'],
            'record' => $mounted['record'] ?? null,
            'arguments' => $mounted['arguments'] ?? null,
            'state' => $state,
            'path' => $path,
        ], static fn (mixed $value): bool => $value !== null));

        if ($response->getStatusCode() !== 200) {
            Assert::fail("Refreshing the form after filling [{$path}] failed with status {$response->getStatusCode()}.");
        }

        if (is_array($components = $response->json('components'))) {
            $this->setFormComponents($components);
        }

        $fresh = $response->json('state');

        return is_array($fresh) ? $fresh : $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function setFormState(array $state): void
    {
        if ($this->mountedAction !== null) {
            $this->mountedAction['data'] = $state;
        } else {
            $this->formState = $state;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    protected function setFormComponents(array $components): void
    {
        if ($this->mountedAction !== null) {
            $this->mountedAction['components'] = $components;
        } else {
            $this->formComponents = $components;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findFormField(string $path): ?array
    {
        $components = $this->mountedAction !== null ? (array) $this->mountedAction['components'] : $this->formComponents;

        return $this->findComponentByStatePath($components, $path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireFormField(string $path): array
    {
        $field = $this->findFormField($path);

        Assert::assertNotNull($field, "Failed asserting that the form has a visible field [{$path}].");

        return $field;
    }

    /**
     * @param  array<mixed>  $tree
     * @return array<string, mixed>|null
     */
    protected function findComponentByStatePath(array $tree, string $path): ?array
    {
        foreach ($tree as $key => $node) {
            // Repeater templates describe items that don't exist yet.
            if ($key === 'template' || ! is_array($node)) {
                continue;
            }

            if (($node['statePath'] ?? null) === $path && isset($node['type'])) {
                return $node;
            }

            if (($found = $this->findComponentByStatePath($node, $path)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function withoutInternalState(array $state): array
    {
        unset($state[Schema::READ_ONLY_STATE_KEY]);

        return $state;
    }

    protected function normalizeErrorPath(string $path): string
    {
        // Filament tests address form state as `data.title`.
        return isset($this->errors[$path]) || ! str_starts_with($path, 'data.') ? $path : substr($path, strlen('data.'));
    }

    /**
     * Whether a validation message is the one Laravel writes for a rule (`required`,
     * `max:100`, `unique`), or contains the given text.
     */
    protected function messageMatchesRule(string $message, string $rule): bool
    {
        if ($message === $rule || ($rule !== '' && str_contains($message, $rule) && str_contains($rule, ' '))) {
            return true;
        }

        $name = \Illuminate\Support\Str::snake(explode(':', $rule, 2)[0]);

        if (! preg_match('/^[a-z_]+$/', $name) || ! Lang::has("validation.{$name}")) {
            return str_contains($message, $rule);
        }

        $lines = Lang::get("validation.{$name}");

        foreach ((array) $lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $pattern = '/^'.preg_replace('/\\\\:[A-Za-z_]+/', '.+?', preg_quote($line, '/')).'$/su';

            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
