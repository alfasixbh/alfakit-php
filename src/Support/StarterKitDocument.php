<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\AlfakitManager;
use AlfaSix\Alfakit\Localization\LocaleResolver;
use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Standalone\StandalonePanel;
use Throwable;

/**
 * The language and writing direction of the whole document in a starter kit application:
 * while the user has picked a right-to-left language for a `starterKit()` panel, every page
 * of the app (AlfaKit's and the kit's own: sidebar, header, settings) is laid out right to
 * left.
 *
 * - `alfakitDocument` (shared with every Inertia page while a panel runs in starter kit mode):
 *   `{ tag, direction }`, which the renderers put on `<html lang dir>` after each visit.
 * - `StarterKitDocument::direction()`: the same direction for the kit's root view
 *   (`<html dir="…">` in `resources/views/app.blade.php`, added by
 *   `alfakit:install --starter-kit`), so the first paint and SSR are already mirrored.
 *
 * `tag` is the app's current language (the panel's on AlfaKit pages, the app's own on its
 * pages, as the kit's `<html lang>` has it), `direction` that of the panel's language.
 */
final class StarterKitDocument
{
    public const PROP = 'alfakitDocument';

    /** Request attribute holding the direction resolved for this request, per panel. */
    private const ATTRIBUTE = 'alfakit.starter_kit_direction';

    /**
     * `<html lang dir>` for the current page, or null on pages of a panel that does not run in
     * starter kit mode (its own root view and runtime set them) and without a starter kit panel.
     *
     * @return array{tag: string, direction: 'ltr'|'rtl'}|null
     */
    public static function resolve(AlfakitManager $manager): ?array
    {
        $panel = self::panel($manager);

        if ($panel === null) {
            return null;
        }

        return [
            'tag' => str_replace('_', '-', app()->getLocale()),
            'direction' => self::directionFor($manager, $panel),
        ];
    }

    /**
     * The writing direction for the root view's `<html dir>`: the panel language's on the
     * pages of a panel and, on the app's own pages, the first `starterKit()` panel's. Never
     * throws (the root view renders error pages too): it falls back to the app locale's.
     *
     * @return 'ltr'|'rtl'
     */
    public static function direction(): string
    {
        try {
            $manager = app(AlfakitManager::class);
            $current = $manager->getCurrentPanel();
            $panel = $current !== null && ! $current instanceof StandalonePanel ? $current : self::panel($manager);

            if ($panel !== null) {
                return self::directionFor($manager, $panel);
            }
        } catch (Throwable $exception) {
            // The root view renders error pages too: never fail it, but don't hide the cause
            // (the app locale's direction may differ from the panel's that the page carries).
            report($exception);
        }

        return TextDirection::detect(app()->getLocale())->value;
    }

    /**
     * The starter kit panel whose language the document follows: the open page's, else the
     * first registered. Null on pages of other panels.
     */
    private static function panel(AlfakitManager $manager): ?Panel
    {
        $current = $manager->getCurrentPanel();

        if ($current !== null && ! $current instanceof StandalonePanel) {
            return $current->isStarterKit() ? $current : null;
        }

        foreach ($manager->getPanels() as $panel) {
            if ($panel->isStarterKit()) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * The panel language's direction, resolved once per request: the shared prop and the root
     * view's `<html dir>` read the same answer, so the first paint and the page the renderers
     * hydrate never disagree (no flip from one direction to the other after load).
     *
     * @return 'ltr'|'rtl'
     */
    private static function directionFor(AlfakitManager $manager, Panel $panel): string
    {
        $request = request();
        $key = self::ATTRIBUTE.'.'.$panel->getId();
        $resolved = $request->attributes->get($key);

        if ($resolved === 'ltr' || $resolved === 'rtl') {
            return $resolved;
        }

        // A panel page runs in the panel's language (`SetPanelLocale`); an app page resolves
        // the language the user picked for the panel (session, cookie, user preference…).
        $locale = $manager->getCurrentPanel() === $panel
            ? $panel->getCurrentLocale()
            : app(LocaleResolver::class)->resolve($panel, $request);
        $direction = $locale->getDirection()->value;

        $request->attributes->set($key, $direction);

        return $direction;
    }
}
