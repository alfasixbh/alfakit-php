<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Pages\Dashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /_alfakit/dashboard/layout` {token, layout: [{id, span, hidden}]} or {token, reset: true}:
 * saves (or resets) the signed-in user's layout of a customizable dashboard.
 *
 * The dashboard is rebuilt from the context token (user, panel access, auth middleware,
 * tenant and `canAccess()` re-checked), so a user only ever writes their own layout of a
 * dashboard they can open, for the tenant the token was issued under. Widgets outside the
 * dashboard's library (or hidden from the user by `canView()`) are refused.
 */
class DashboardLayoutController
{
    use ResolvesContext;

    public function update(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        $page = $this->resolvePage($context);

        abort_unless($page instanceof Dashboard && $page->isCustomizable(), 403);

        if ($request->boolean('reset')) {
            $page->saveDashboardLayout(null);

            return $this->respond($page);
        }

        $layout = $request->input('layout');

        if (! is_array($layout) || ! array_is_list($layout) || count($layout) > 200) {
            throw ValidationException::withMessages(['layout' => __('alfakit::panel.dashboard.customize.invalid')]);
        }

        $library = $page->getLibraryWidgets();
        $entries = [];

        foreach ($layout as $index => $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            $span = is_array($entry) ? ($entry['span'] ?? null) : null;

            if (! is_string($id) || ! isset($library[$id]) || ($span !== null && ! is_int($span) && $span !== 'full')) {
                throw ValidationException::withMessages(["layout.{$index}" => __('alfakit::panel.dashboard.customize.invalid')]);
            }

            $entries[] = ['id' => $id, 'span' => $span, 'hidden' => (bool) ($entry['hidden'] ?? false)];
        }

        $page->saveDashboardLayout($entries);

        return $this->respond($page);
    }

    protected function respond(Dashboard $page): JsonResponse
    {
        return response()->json([
            'layout' => $page->getDashboardLayout(),
            'customized' => $page->hasCustomDashboardLayout(),
        ]);
    }
}
