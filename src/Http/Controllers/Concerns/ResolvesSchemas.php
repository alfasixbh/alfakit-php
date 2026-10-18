<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use Illuminate\Http\Request;

/**
 * Rebuilds the schema an internal request refers to: a page schema (`form`, `infolist`),
 * the modal form of an action (`action:{owner}.{name}`), or the modal form of an action
 * attached to a field (`action:field:{schema}::{key}.{name}`).
 *
 * Field actions are addressed as `field:{schema}::{key}`: the schema the field lives in
 * and the field's key (its state path). The field must be visible, and the action must
 * be one the field defines, so a request can't reach anything the page doesn't render.
 */
trait ResolvesSchemas
{
    use ResolvesActionSelection;

    public const FIELD_COMPONENT_PREFIX = 'field:';

    protected function resolveSchema(Page $page, string $component, Request $request): Schema
    {
        if (str_starts_with($component, 'action:')) {
            [$owner, $name] = $this->splitActionComponent(substr($component, strlen('action:')));

            if (str_starts_with($owner, self::FIELD_COMPONENT_PREFIX)) {
                // The owning form's state comes along so the field (e.g. one inside a
                // repeater item that only exists in the browser) can be found.
                [$action] = $this->resolveFieldAction($page, substr($owner, strlen(self::FIELD_COMPONENT_PREFIX)), $name, $this->requestState($request, 'ownerState'));
            } else {
                // Bulk actions (and the actions nested in their modals) are resolved with the
                // request's `bulk`/`records` flags and receive the selection, exactly as
                // when they run, so their forms can refresh and search options.
                try {
                    $action = $page->resolveAction($owner, $name, $this->isBulkActionRequest($request));
                } catch (ActionNotResolvableException $exception) {
                    abort(404, $exception->getMessage());
                }

                $this->applyActionTarget($action, $page, $owner === 'page' ? null : $owner, $request);
            }

            // Same checks as running the action: hidden or unauthorised actions expose
            // neither their form nor its options.
            abort_unless($action->isVisible() && ! $action->isDisabled(), 403);

            $schema = $action->getForm();
        } elseif (str_ends_with($component, '.filters')) {
            // The filters form of a table (`table.filters`, `relationManager:X.filters`,
            // `widget:X.filters`): searchable select filters load their options here.
            $schema = $this->resolveActionTable($page, substr($component, 0, -strlen('.filters')))?->getFiltersSchema();
        } else {
            $schema = $page->getSchema($component);
        }

        abort_if($schema === null, 404, "Schema [{$component}] not found.");

        return $schema;
    }

    /**
     * Form state sent with a request: an array in a JSON body, or a JSON string in a query
     * string. GET requests (select options, picker tables) send JSON because a query string
     * drops nulls and empty arrays, and with them repeater items that are still blank.
     *
     * @return array<string, mixed>|null
     */
    protected function requestState(Request $request, string $key): ?array
    {
        $state = $request->input($key);

        if (is_string($state)) {
            $state = json_decode($state, true);
        }

        return is_array($state) ? $state : null;
    }

    /**
     * Resolve `{schema}::{key}` to the schema and the visible component it names, with the
     * browser's state applied first when given.
     *
     * @param  array<string, mixed>|null  $state
     * @return array{0: Schema, 1: Component}
     */
    protected function resolveSchemaComponent(Page $page, string $reference, ?array $state): array
    {
        $position = strrpos($reference, '::');

        abort_if($position === false, 400, 'Malformed field reference.');

        $schemaName = substr($reference, 0, $position);
        $key = substr($reference, $position + 2);

        abort_if($schemaName === '' || $key === '' || str_starts_with($schemaName, self::FIELD_COMPONENT_PREFIX), 400, 'Malformed field reference.');

        $schema = $this->resolveSchema($page, $schemaName, request());

        if ($state !== null) {
            $schema->applyClientState($state);
        }

        $component = $schema->getFieldByStatePath($key);

        if ($component === null) {
            foreach ($schema->getFlatComponents(withHidden: true) as $candidate) {
                if ($candidate->getKey() === $key) {
                    $component = $candidate;

                    break;
                }
            }
        }

        // Hidden components don't exist as far as the browser is concerned.
        abort_if($component === null || $component->isHiddenInTree(), 404, 'Field not found.');

        return [$schema, $component];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{0: Action, 1: Schema, 2: Component}
     */
    protected function resolveFieldAction(Page $page, string $reference, string $name, ?array $state): array
    {
        abort_if($name === '', 400, 'Missing action name.');

        [$schema, $component] = $this->resolveSchemaComponent($page, $reference, $state);

        $action = method_exists($component, 'getFieldAction') ? $component->getFieldAction($name) : null;

        abort_if(! $action instanceof Action, 404, "Action [{$name}] not found on the field.");

        // A disabled field's actions are shown disabled and never run.
        abort_if($component->isDisabled(), 403);

        $action->context($page);

        return [$action, $schema, $component];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitActionComponent(string $value): array
    {
        if (! str_contains($value, '.')) {
            return ['page', $value];
        }

        $position = strrpos($value, '.');

        return [substr($value, 0, $position), substr($value, $position + 1)];
    }
}
