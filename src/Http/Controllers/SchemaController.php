<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\IconPicker;
use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesSchemas;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ServesIconPickerOptions;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ServesRichEditorOptions;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\SchemaDiff;
use AlfaSix\Alfakit\Tables\TableQueryString;
use AlfaSix\Alfakit\Tables\TableState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reactive form support: re-evaluate a schema with new state, and serve searchable
 * select options.
 */
class SchemaController
{
    use ResolvesContext;
    use ResolvesSchemas;
    use ServesIconPickerOptions;
    use ServesRichEditorOptions;

    /**
     * POST {token, component, state, path, old?, fingerprints?}: run `afterStateUpdated`
     * for `path` and return the re-rendered components and state. With the fingerprints of
     * the components the browser holds, only the changed components and state keys are
     * returned (see `SchemaDiff`).
     */
    public function refresh(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        // "Select all" bulk actions see the table as the page shows it.
        TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $schema = $this->resolveSchema($page, $context->component ?? (string) $request->input('component', 'form'), $request);

        $state = (array) $request->input('state', []);
        $schema->applyClientState($state);

        $path = $request->input('path');
        // `partiallyRenderComponentsAfterStateUpdated()` / `skipRenderAfterStateUpdated()`
        // on the updated field limit which components are re-sent.
        $only = null;

        if (is_string($path) && $path !== '') {
            // `old` is the value the browser last received from the server; without it, fall
            // back to the server-filled value (record or default).
            $old = $request->has('old') ? $request->input('old') : $schema->getServerStateAt($path);

            $schema->markAsDirty($path);
            $schema->callAfterStateUpdated($path, $old);
            $only = $schema->getFieldByStatePath($path)?->getPartialRenderTargets();
        }

        $fingerprints = $request->input('fingerprints');

        return response()->json(SchemaDiff::respond($schema->toPayload(), is_array($fingerprints) ? $fingerprints : null, $state, $only));
    }

    /**
     * POST {token, component, state, step}: validate the fields of one wizard step (`step`
     * is the Step component's key) with the browser's state, running the step's
     * `beforeValidation()` and `afterValidation()` hooks. Answers 204, or 422 with the
     * errors of that step's fields only.
     */
    public function validate(Request $request): Response
    {
        $context = $this->resolveContext($request);
        TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $schema = $this->resolveSchema($page, $context->component ?? (string) $request->input('component', 'form'), $request);

        $schema->applyClientState((array) $request->input('state', []));

        $key = $request->input('step');
        $step = null;

        foreach ($schema->getFlatComponents() as $component) {
            if ($component instanceof Step && $component->getKey() === $key) {
                $step = $component;

                break;
            }
        }

        abort_if($step === null, 404, 'Wizard step not found.');

        try {
            $step->callBeforeValidation();
            $schema->validateOnly(array_keys($step->getChildSchema()->getFlatFields()));
            $step->callAfterValidation();
        } catch (Halt|Cancel) {
            // `throw new Halt` (or `$this->halt()`) in a step hook keeps the wizard on this
            // step; the notifications it sent are shown right away.
            return response()->json(['halted' => true, 'notifications' => $this->pullFlashedNotifications($request)]);
        }

        return response()->noContent();
    }

    /**
     * The notifications sent during this request, taken out of the flash data so they are
     * not shown a second time on the next page.
     *
     * @return array<int, mixed>
     */
    protected function pullFlashedNotifications(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $flash = (array) $request->session()->get(SessionKey::FLASH_DATA, []);
        $notifications = array_values((array) ($flash[Notification::FLASH_KEY] ?? []));
        unset($flash[Notification::FLASH_KEY]);

        if ($flash === []) {
            $request->session()->forget(SessionKey::FLASH_DATA);
        } else {
            $request->session()->flash(SessionKey::FLASH_DATA, $flash);
        }

        return $notifications;
    }

    /**
     * GET {token, component, path, search?, values[]?, state?}: options for a searchable
     * select (`state`, the form's state as JSON, finds fields of repeater items that only
     * exist in the browser).
     * With `table=1` (ModalTableSelect): the picker table for `table_search`, `table_page`,
     * `table_sort`, `table_filters`… and the labels of its records.
     */
    public function options(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $schema = $this->resolveSchema($page, $context->component ?? (string) $request->query('component', 'form'), $request);

        // Searchable select filters in the picker of a ModalTableSelect (`pickerFilters` is
        // its path): `state` is the filters form's, `ownerState` the owning form's.
        $picker = $request->query('pickerFilters');

        if (is_string($picker) && $picker !== '') {
            if (($ownerState = $this->requestState($request, 'ownerState')) !== null) {
                $schema->applyClientState($ownerState);
            }

            $owner = $schema->getFieldByStatePath($picker);

            abort_unless($owner instanceof ModalTableSelect && ! $owner->isHiddenInTree(), 404, 'Select field not found.');
            abort_if($owner->isDisabled() || $owner->isReadOnly(), 403);

            $schema = $owner->getFiltersSchema();
        }

        if (($state = $this->requestState($request, 'state')) !== null) {
            $schema->applyClientState($state);
        }

        // Rich editor mention search and custom block previews.
        if ($this->isRichEditorOptionsRequest($request)) {
            return $this->richEditorOptions($schema, $request);
        }

        $field = $schema->getFieldByStatePath((string) $request->query('path'));

        // IconPicker search: a page of icons for `search`, `set` and `page`.
        if ($field instanceof IconPicker) {
            return $this->iconPickerOptions($field, $request);
        }

        // Hidden fields don't exist as far as the browser is concerned.
        abort_unless($field instanceof Select && ! $field->isHiddenInTree(), 404, 'Select field not found.');

        if ($request->has('values')) {
            return response()->json(['labels' => $field->getOptionLabelsFor((array) $request->query('values'))]);
        }

        abort_if($field->isDisabled(), 403);

        // The picker table of a ModalTableSelect: its state travels as `table_*` keys.
        if ($field instanceof ModalTableSelect && $request->boolean('table')) {
            abort_if($field->isReadOnly(), 403);

            return response()->json($field->getTablePayload(
                TableState::fromRequest($request, ModalTableSelect::TABLE_STATE_IDENTIFIER),
            ));
        }

        $results = $field->getSearchResults((string) $request->query('search', ''));

        return response()->json([
            'options' => collect($results)->map(static fn ($label, $value): array => ['value' => (string) $value, 'label' => (string) $label])->values()->all(),
        ]);
    }
}
