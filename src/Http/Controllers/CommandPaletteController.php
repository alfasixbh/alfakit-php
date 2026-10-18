<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\CommandPalette\RecentRecords;
use AlfaSix\Alfakit\Support\Url;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * What the command palette loads when it opens: "create" links of the resources the user
 * may create records in, and the records they opened last (re-authorised on every read).
 * Runs behind the panel's auth (and tenant) middleware, like global search.
 */
class CommandPaletteController
{
    public function __invoke(): JsonResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $user = $panel->auth()->user();

        if ($user === null || ! $panel->hasCommandPalette() || ($panel->hasTenancy() && $panel->getTenant() === null)) {
            return response()->json(['create' => [], 'recent' => []]);
        }

        $create = [];

        foreach ($panel->getResourceRegistrations() as [$resource, $configuration]) {
            $link = $resource::withConfiguration($configuration, static function () use ($resource): ?array {
                try {
                    if ($resource::isNested() || ! $resource::hasPage('create') || ! $resource::canAccess() || ! $resource::canCreate()) {
                        return null;
                    }

                    $url = Url::sanitize($resource::getUrl('create'));

                    return $url === null ? null : array_filter([
                        'label' => __('alfakit::panel.command_palette.create', ['label' => $resource::getModelLabel()]),
                        'url' => $url,
                        'icon' => $resource::getNavigationIcon(),
                        'keywords' => [$resource::getPluralModelLabel()],
                    ], static fn (mixed $value): bool => $value !== null);
                } catch (Throwable) {
                    return null;
                }
            });

            if ($link !== null) {
                $create[] = $link;
            }
        }

        return response()->json([
            'create' => $create,
            'recent' => RecentRecords::get($panel, $user),
        ]);
    }
}
