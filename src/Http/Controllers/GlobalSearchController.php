<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\GlobalSearch\GlobalSearchProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $search = trim((string) $request->query('search', ''));

        if ($search === '' || ! $panel->hasGlobalSearch()) {
            return response()->json(['groups' => []]);
        }

        return response()->json(['groups' => (new GlobalSearchProvider($panel))->search($search)]);
    }
}
