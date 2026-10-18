<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesSchemas;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\DeferredLoading;
use AlfaSix\Alfakit\Tables\TableQueryString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loads what a page's first response left out: the children of a `deferLoading()`
 * component, or the badge of a `Tab::deferBadge()` tab.
 *
 * POST {token, component: "{schema}::{key}", state?, only?: "badge"}
 */
class DeferredSchemaController
{
    use ResolvesContext;
    use ResolvesSchemas;

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        TableQueryString::apply($request);
        $page = $this->resolvePage($context);

        $reference = $request->input('component');
        abort_unless(is_string($reference) && $reference !== '', 400, 'Missing component reference.');

        // Hidden components 404 here, exactly as for field actions.
        [, $component] = $this->resolveSchemaComponent($page, $reference, $this->requestState($request, 'state'));

        if ($request->input('only') === 'badge') {
            abort_unless($component instanceof Tab, 404, 'Not a tab.');

            return response()->json(['badge' => $component->getBadgeSchema()]);
        }

        return response()->json([
            'component' => DeferredLoading::withPlaceholders(static fn (): array => $component->toSchema(), false),
        ]);
    }
}
