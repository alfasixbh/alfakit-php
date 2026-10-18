<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST {token, original, state}: what changed on an Edit page's record since its form was
 * loaded. Returns the record's current version and, per changed field, its label, the
 * saved value and the user's unsaved one (see `EditRecord::getEditConflict()`).
 * Authorised like the page itself (the token's page, user and tenant; `update` on the record).
 */
class ConflictController
{
    use ResolvesContext;

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        $page = $this->resolvePage($context);

        abort_unless($page instanceof EditRecord && $page->detectsConflicts(), 404);

        $original = $request->input('original');
        $state = $request->input('state');

        return response()->json($page->getEditConflict(is_array($original) ? $original : [], is_array($state) ? $state : []));
    }
}
