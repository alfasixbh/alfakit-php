<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\AlfakitManager;
use Inertia\Inertia;

/**
 * Shares the navigation of `starterKit()` panels with every Inertia page of the application
 * (`alfakitNavigation`), so the host's sidebar lists the panel's resources and pages on the
 * host's own pages (dashboard, settings) as well as on the panel's.
 */
final class StarterKitNavigation
{
    public const PROP = 'alfakitNavigation';

    /** Register the shared prop when at least one panel runs in starter kit mode. */
    public static function register(AlfakitManager $manager): void
    {
        foreach ($manager->getPanels() as $panel) {
            if ($panel->isStarterKit()) {
                // A closure: resolved once per full page response, skipped by partial reloads.
                Inertia::share(self::PROP, static fn (): ?array => self::resolve($manager));

                return;
            }
        }
    }

    /**
     * One entry per starter kit panel the user may open. Null for guests, and on pages of
     * other (standalone) panels, which render their own sidebar.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function resolve(AlfakitManager $manager): ?array
    {
        $current = $manager->getCurrentPanel();

        if ($current !== null && ! $current->isStarterKit()) {
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
}
