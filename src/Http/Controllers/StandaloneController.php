<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Standalone\Component;
use AlfaSix\Alfakit\Tables\TableQueryString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints specific to components used outside panels. Their host page is not an Alfakit
 * page, so lazy and polled components are fetched here (by context token) instead of
 * through an Inertia partial reload, and uploads go through the same validation as in a
 * panel, with guest access controlled by `alfakit.standalone.guest_uploads`.
 */
class StandaloneController extends UploadController
{
    use ResolvesContext;

    /**
     * GET {token, targets}: the fresh schema of the named components (`table`,
     * `widget:<id>`), like a poll of a panel page.
     */
    public function components(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        $page = $this->resolvePage($context);

        abort_unless($page instanceof Component, 400, 'Only standalone components can be fetched here.');

        // Tables keep the host page's sort, search and filters (sent as `tableQuery`).
        TableQueryString::apply($request);

        // Same shape as the `X-Alfakit-Poll` header of a panel poll (`Page::pollTargets()`).
        $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->query('targets', ''))))));

        return response()->json($page->getPolledComponents(array_slice($targets, 0, 50)));
    }

    public function storeUpload(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    public function destroyUpload(Request $request): JsonResponse
    {
        return $this->destroy($request);
    }
}
