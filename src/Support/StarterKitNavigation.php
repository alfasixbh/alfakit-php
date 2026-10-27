<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\AlfakitManager;
use AlfaSix\Alfakit\Standalone\StandalonePanel;
use Inertia\Inertia;

/**
 * Shares the navigation of `starterKit()` panels with every Inertia page of the application
 * (`alfakitNavigation`), so the host's sidebar lists the panel's resources and pages on the
 * host's own pages (dashboard, settings) as well as on the panel's, and their dashboard
 * widgets with the host's dashboard (`alfakitWidgets`), and the document's language and
 * direction (`alfakitDocument`, see `StarterKitDocument`).
 */
final class StarterKitNavigation
{
    public const PROP = 'alfakitNavigation';

    /** The panels' dashboard widgets on the host's dashboard (`<AlfakitWidgets />`). */
    public const WIDGETS_PROP = 'alfakitWidgets';

    /** Register the shared prop when at least one panel runs in starter kit mode. */
    public static function register(AlfakitManager $manager): void
    {
        foreach ($manager->getPanels() as $panel) {
            if ($panel->isStarterKit()) {
                // A closure: resolved once per full page response, skipped by partial reloads.
                Inertia::share(self::PROP, static fn (): ?array => self::resolve($manager));
                // Null (and nothing built) except on the pages of `starterKitDashboard()`.
                Inertia::share(self::WIDGETS_PROP, static fn (): ?array => self::resolveWidgets($manager));
                // `<html lang dir>` of every page, guests' included (the kit's login page too).
                Inertia::share(StarterKitDocument::PROP, static fn (): ?array => StarterKitDocument::resolve($manager));

                return;
            }
        }
    }

    /**
     * One entry per starter kit panel the user may open. Null for guests, and on pages of
     * other panels, which render their own sidebar. The hidden standalone and auth-kit panels
     * (current while a host page builds a standalone component or the auth kit's /account)
     * render none, so the host's sidebar keeps the links there.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function resolve(AlfakitManager $manager): ?array
    {
        $current = $manager->getCurrentPanel();

        if ($current !== null && ! $current->isStarterKit() && ! $current instanceof StandalonePanel) {
            return null;
        }

        $navigations = [];

        foreach ($manager->getPanels() as $panel) {
            if (! $panel->isStarterKit()) {
                continue;
            }

            $navigation = $panel->getStarterKitNavigationSchema();

            if ($navigation !== null) {
                $navigations[] = $navigation;
            }
        }

        return $navigations === [] ? null : $navigations;
    }

    /**
     * One entry (`{ id, component }`) per starter kit panel whose widgets the current host
     * page shows (`starterKitDashboard()`, the `dashboard` route by default). Null on other
     * pages, for guests and users who may not open the panel, and without widgets.
     *
     * @return list<array{id: string, component: array<string, mixed>}>|null
     */
    public static function resolveWidgets(AlfakitManager $manager): ?array
    {
        if ($manager->getCurrentPanel() !== null) {
            return null;
        }

        $entries = [];

        foreach ($manager->getPanels() as $panel) {
            $component = $panel->getStarterKitDashboardSchema();

            if ($component !== null) {
                $entries[] = ['id' => $panel->getId(), 'component' => $component];
            }
        }

        return $entries === [] ? null : $entries;
    }
}
