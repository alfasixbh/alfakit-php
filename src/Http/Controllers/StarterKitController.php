<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * `starterKit()` panels: the panel data the drop-in header components (`<AlfakitSearch />`,
 * `<AlfakitNotifications />`, `<AlfakitTenantSwitcher />`) render with on any page of the host
 * application, loaded the first time one of them renders. The same shape as the `alfakit`
 * prop of the panel's own pages, so the components are the panel's.
 */
class StarterKitController
{
    public function __invoke(): JsonResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        abort_unless($panel->isStarterKit(), 404);

        return response()->json([
            ...$panel->toSchema(),
            'localization' => $panel->getLocalizationSchema(),
            'csrf' => csrf_token(),
            'translations' => $panel->getFrontendTranslations(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
