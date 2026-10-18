<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Forms\Components\IconPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IconPicker search on the options endpoint: GET {token, component, path, search?, set?,
 * page?} answers one page of matching icons (`{icons: [{value, icon}], page, hasMore,
 * total}`). The field must be visible and editable.
 */
trait ServesIconPickerOptions
{
    protected function iconPickerOptions(IconPicker $field, Request $request): JsonResponse
    {
        abort_if($field->isHiddenInTree(), 404, 'Icon picker not found.');
        abort_if($field->isDisabled() || $field->isReadOnly(), 403);

        $set = $request->query('set');

        return response()->json($field->getSearchResults(
            mb_substr((string) $request->query('search', ''), 0, 100),
            is_string($set) && $set !== '' ? $set : null,
            max(1, min(10000, (int) $request->query('page', 1))),
        ));
    }
}
