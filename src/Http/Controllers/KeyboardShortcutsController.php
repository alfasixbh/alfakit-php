<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\CommandPalette\KeyboardShortcuts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores the signed-in user's customised keyboard shortcuts for the current panel. The
 * body is `{shortcuts: {id: ['mod+j'] | [] | null}}`: a list replaces the shortcut's keys
 * (empty turns it off), `null` restores the default.
 */
class KeyboardShortcutsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $user = $panel->auth()->user();

        abort_if($user === null, 401);
        abort_unless($panel->hasCustomizableKeyboardShortcuts(), 403);

        $input = $request->input('shortcuts');

        abort_unless(is_array($input), 422, 'Invalid shortcuts.');

        $changes = KeyboardShortcuts::parse($input);

        abort_if($changes === null, 422, 'Invalid shortcuts.');

        return response()->json(['shortcuts' => (object) KeyboardShortcuts::update($panel, $user, $changes)]);
    }
}
