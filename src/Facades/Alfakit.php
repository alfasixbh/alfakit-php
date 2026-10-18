<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Facades;

use AlfaSix\Alfakit\AlfakitManager;
use AlfaSix\Alfakit\Panel;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void registerPanel(Panel $panel)
 * @method static array<string, Panel> getPanels()
 * @method static Panel getPanel(?string $id = null)
 * @method static bool hasPanel(string $id)
 * @method static Panel|null getDefaultPanel()
 * @method static void setCurrentPanel(?Panel $panel)
 * @method static Panel|null getCurrentPanel()
 * @method static Panel getCurrentOrDefaultPanel()
 * @method static \Illuminate\Database\Eloquent\Model|null getTenant()
 * @method static void setTenant(?\Illuminate\Database\Eloquent\Model $tenant)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null auth()
 * @method static \Inertia\Response render(string $component, array<string, mixed> $props = [])
 * @method static \AlfaSix\Alfakit\AuthKit\AuthKitPanel getAuthKitPanel()
 * @method static \AlfaSix\Alfakit\Standalone\StandalonePanel getStandalonePanel()
 *
 * @see AlfakitManager
 */
class Alfakit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AlfakitManager::class;
    }
}
