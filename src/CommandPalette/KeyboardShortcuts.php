<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\CommandPalette;

use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The user's customised keyboard shortcuts (`{shortcutId: ['mod+j']}`; an empty list turns
 * a shortcut off). Only well-formed bindings are stored: they are data for the renderer's
 * key matcher, never code.
 */
final class KeyboardShortcuts
{
    public const PREFERENCE_KEY = 'shortcuts';

    public const MAX_SHORTCUTS = 100;

    public const MAX_BINDINGS = 3;

    /** Shortcut ids: `palette.open`, `command:clear-cache`, `go.dashboard`. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9:._\-]{0,99}$/';

    /**
     * One step: optional modifiers and a key (`mod+shift+k`, `?`, `/`, `arrowup`). A binding
     * is up to three steps separated by single spaces (`g d`).
     */
    public const STEP_PATTERN = '/^(?:(?:mod|ctrl|control|meta|cmd|command|alt|option|shift)\+){0,4}(?:[a-z0-9]|f[1-9]|f1[0-2]|[\/?.,;\'\[\]\\\\=`\-]|escape|esc|enter|tab|space|backspace|delete|arrowup|arrowdown|arrowleft|arrowright|home|end|pageup|pagedown)$/';

    public static function isValidBinding(mixed $binding): bool
    {
        if (! is_string($binding) || $binding === '' || strlen($binding) > 60 || $binding !== strtolower($binding)) {
            return false;
        }

        $steps = explode(' ', $binding);

        if (count($steps) > 3) {
            return false;
        }

        foreach ($steps as $step) {
            if (preg_match(self::STEP_PATTERN, $step) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate posted overrides: `null` resets a shortcut, a list of bindings replaces it.
     *
     * @param  array<mixed>  $input
     * @return array{set: array<string, array<int, string>>, reset: array<int, string>}|null null when invalid
     */
    public static function parse(array $input): ?array
    {
        if (count($input) > self::MAX_SHORTCUTS) {
            return null;
        }

        $set = [];
        $reset = [];

        foreach ($input as $id => $bindings) {
            if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                return null;
            }

            if ($bindings === null) {
                $reset[] = $id;

                continue;
            }

            if (! is_array($bindings) || count($bindings) > self::MAX_BINDINGS || ! array_is_list($bindings)) {
                return null;
            }

            foreach ($bindings as $binding) {
                if (! self::isValidBinding($binding)) {
                    return null;
                }
            }

            /** @var array<int, string> $bindings */
            $set[$id] = array_values(array_unique($bindings));
        }

        return ['set' => $set, 'reset' => $reset];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function overridesFor(Panel $panel, Authenticatable $user): array
    {
        $stored = UserPreferences::get($panel, $user, self::PREFERENCE_KEY);

        if (! is_array($stored)) {
            return [];
        }

        $overrides = [];

        // Re-validated on read: the stored value is data from the user.
        foreach ($stored as $id => $bindings) {
            if (is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 && is_array($bindings) && count($bindings) <= self::MAX_BINDINGS && array_filter($bindings, self::isValidBinding(...)) === $bindings) {
                $overrides[$id] = array_values($bindings);
            }
        }

        return array_slice($overrides, 0, self::MAX_SHORTCUTS, true);
    }

    /**
     * Apply validated changes to the stored overrides.
     *
     * @param  array{set: array<string, array<int, string>>, reset: array<int, string>}  $changes
     * @return array<string, array<int, string>>
     */
    public static function update(Panel $panel, Authenticatable $user, array $changes): array
    {
        $overrides = self::overridesFor($panel, $user);

        foreach ($changes['reset'] as $id) {
            unset($overrides[$id]);
        }

        $overrides = array_slice([...$overrides, ...$changes['set']], 0, self::MAX_SHORTCUTS, true);

        UserPreferences::put($panel, $user, self::PREFERENCE_KEY, $overrides === [] ? null : $overrides);

        return $overrides;
    }
}
