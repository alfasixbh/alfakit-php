<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

use AlfaSix\Alfakit\Panel;

/**
 * A reusable bundle of resources, pages, widgets and configuration for a panel.
 *
 * ```php
 * $panel->plugin(BlogPlugin::make());
 * ```
 */
interface Plugin
{
    public function getId(): string;

    /**
     * Called when the panel is configured (register resources, pages, middleware…).
     */
    public function register(Panel $panel): void;

    /**
     * Called once the panel has booted for the current request.
     */
    public function boot(Panel $panel): void;
}
