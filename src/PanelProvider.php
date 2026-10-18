<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit;

use Illuminate\Support\ServiceProvider;

/**
 * Extend this provider once per panel and configure the panel in `panel()`.
 *
 * ```php
 * class AdminPanelProvider extends PanelProvider
 * {
 *     public function panel(Panel $panel): Panel
 *     {
 *         return $panel->default()->id('admin')->path('admin')->login()
 *             ->discoverResources(in: app_path('Alfakit/Resources'), for: 'App\\Alfakit\\Resources');
 *     }
 * }
 * ```
 *
 * `Panel::configureUsing()` callbacks run after `panel()`, so they see the panel's real
 * id (`$panel->getId()`) and can target one panel in a multi-panel or modular app.
 */
abstract class PanelProvider extends ServiceProvider
{
    abstract public function panel(Panel $panel): Panel;

    public function register(): void
    {
        $this->app->afterResolving(AlfakitManager::class, function (AlfakitManager $manager): void {
            $manager->registerPanel($this->makePanel());
        });

        if ($this->app->resolved(AlfakitManager::class)) {
            $this->app->make(AlfakitManager::class)->registerPanel($this->makePanel());
        }
    }

    /**
     * Builds the panel, then applies `Panel::configureUsing()` callbacks once its id is known.
     */
    protected function makePanel(): Panel
    {
        return $this->panel(new Panel)->configure();
    }
}
