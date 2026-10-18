<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * An action whose modal opens as soon as the page loads, like Filament's `$defaultAction`:
 *
 * ```php
 * protected ?string $defaultAction = 'onboarding';
 * ```
 *
 * or from the URL: `?defaultAction=onboarding&defaultActionArguments[step]=2` (Filament's
 * `?action=onboarding&actionArguments[step]=2` works too), and on list pages
 * `Resource::getUrl('index', ['tableAction' => 'edit', 'tableActionRecord' => $id])`.
 *
 * The page only tells the renderer which action to open; the modal is then fetched through
 * the action endpoint like a click would, so visibility, authorisation and record scoping
 * apply as usual. Actions that would run straight away (no modal) and links are never
 * started from a URL: a link someone else sent must not change data on its own.
 */
trait HasDefaultAction
{
    /** Query-string keys read by `getRequestedDefaultAction()`. */
    public const DEFAULT_ACTION_QUERY_KEY = 'defaultAction';

    public const DEFAULT_ACTION_ARGUMENTS_QUERY_KEY = 'defaultActionArguments';

    public const DEFAULT_ACTION_CONTEXT_QUERY_KEY = 'defaultActionContext';

    /** Filament's deep-link keys, accepted as aliases: `?action=`, `actionArguments`, `actionComponent=page`. */
    public const FILAMENT_ACTION_QUERY_KEY = 'action';

    public const FILAMENT_ACTION_ARGUMENTS_QUERY_KEY = 'actionArguments';

    public const FILAMENT_ACTION_COMPONENT_QUERY_KEY = 'actionComponent';

    /** Name of an action whose modal opens when the page loads. */
    protected ?string $defaultAction = null;

    /** @var array<string, mixed>|null Arguments the default action is opened with. */
    protected ?array $defaultActionArguments = null;

    /**
     * Where the default action lives: `['table' => true, 'recordKey' => 5]` for a table
     * record action, `['component' => 'relationManager:App\…']` for another component.
     * Page actions (header, form and schema actions) need none.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $defaultActionContext = null;

    public function getDefaultAction(): ?string
    {
        return $this->defaultAction;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultActionArguments(): array
    {
        return $this->defaultActionArguments ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultActionContext(): array
    {
        return $this->defaultActionContext ?? [];
    }

    /**
     * The action to open: from the query string when it names one, else the page's own.
     *
     * @return array{name: string, arguments: array<string, mixed>, context: array<string, mixed>}|null
     */
    protected function getRequestedDefaultAction(): ?array
    {
        $request = request();
        $name = $request->query(self::DEFAULT_ACTION_QUERY_KEY);

        if (is_string($name) && $name !== '') {
            return [
                'name' => $name,
                'arguments' => self::queryArray($request->query(self::DEFAULT_ACTION_ARGUMENTS_QUERY_KEY)),
                'context' => self::queryArray($request->query(self::DEFAULT_ACTION_CONTEXT_QUERY_KEY)),
            ];
        }

        // Filament's `?action=sendEmail&actionArguments[source]=email` links. They target the
        // page; `actionComponent` naming another component (a relation manager) is not
        // supported, so such a link opens nothing rather than the wrong action.
        $name = $request->query(self::FILAMENT_ACTION_QUERY_KEY);
        $target = $request->query(self::FILAMENT_ACTION_COMPONENT_QUERY_KEY);

        if (is_string($name) && $name !== '') {
            if ($target !== null && $target !== 'page') {
                return null;
            }

            return [
                'name' => $name,
                'arguments' => self::queryArray($request->query(self::FILAMENT_ACTION_ARGUMENTS_QUERY_KEY)),
                // `actionContext` (schema component, record key) is not read: page actions only.
                'context' => [],
            ];
        }

        $name = $this->getDefaultAction();

        if ($name === null || $name === '') {
            return null;
        }

        return ['name' => $name, 'arguments' => $this->getDefaultActionArguments(), 'context' => $this->getDefaultActionContext()];
    }

    /**
     * What the renderer needs to open the default action after the page hydrated: the
     * action's button schema, its context component, record and arguments. Null when
     * there is none, or it can't be opened (unknown, hidden, disabled, no modal, a link,
     * or a record outside the table's query).
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultActionSchema(): ?array
    {
        $requested = $this->getRequestedDefaultAction();

        if ($requested === null) {
            return null;
        }

        $context = $requested['context'];
        $component = match (true) {
            is_string($context['component'] ?? null) && $context['component'] !== '' => $context['component'],
            (bool) ($context['table'] ?? false) => 'table',
            default => 'page',
        };
        $recordKey = $context['recordKey'] ?? null;

        if ($recordKey !== null && ! is_scalar($recordKey)) {
            return null;
        }

        try {
            $action = $this->resolveAction($component, $requested['name']);
        } catch (ActionNotResolvableException) {
            return null;
        }

        $action->arguments($requested['arguments']);

        if ($recordKey !== null && $recordKey !== '') {
            $table = $this->resolveDefaultActionTable($component);
            $record = $table?->getRecord($recordKey);

            if (! $record instanceof Model && ! is_array($record)) {
                return null;
            }

            $action->record($record)->recordKey($recordKey);
        }

        if (! $action->isVisible() || $action->isDisabled() || $action->isLink() || ! $action->hasModal()) {
            return null;
        }

        return array_filter([
            'action' => $action->toSchema(),
            'component' => $component,
            'record' => $recordKey === '' ? null : $recordKey,
            'arguments' => $requested['arguments'] ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function resolveDefaultActionTable(string $component): ?Table
    {
        return match (true) {
            $component === 'table' && method_exists($this, 'getTable') => $this->getTable(),
            str_starts_with($component, 'table:') && method_exists($this, 'getNamedTable') => $this->getNamedTable(substr($component, strlen('table:'))),
            str_starts_with($component, 'relationManager:') && method_exists($this, 'getRelationManager') => $this->getRelationManager(substr($component, strlen('relationManager:')))?->getTable(),
            default => null,
        };
    }

    /**
     * An array query value: `?x[a]=1` or a JSON object (`?x={"a":1}`).
     *
     * @return array<string, mixed>
     */
    protected static function queryArray(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
