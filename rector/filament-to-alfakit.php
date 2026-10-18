<?php

declare(strict_types=1);

/*
 * Rector set used by `php artisan alfakit:migrate-from-filament` when the application has
 * Rector installed (`vendor/bin/rector`). Run it yourself with:
 *
 *   vendor/bin/rector process app bootstrap/providers.php --config vendor/alfasix/alfakit/rector/filament-to-alfakit.php
 *
 * The class map lives in AlfaSix\Alfakit\Migration\FilamentClassMap; the custom rules live in
 * AlfaSix\Alfakit\Migration\Rector. Non-convertible items are appended to the file named by
 * the ALFAKIT_MIGRATION_REPORT environment variable (one JSON object per line).
 */

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Migration\Rector\FilamentClassMembersRector;
use AlfaSix\Alfakit\Migration\Rector\FilamentIconsAndColorsRector;
use AlfaSix\Alfakit\Migration\Rector\FilamentLivewireFormRector;
use AlfaSix\Alfakit\Migration\Rector\FilamentMethodsRector;
use AlfaSix\Alfakit\Migration\Rector\FilamentNamespaceRector;
use AlfaSix\Alfakit\Migration\Rector\FilamentPanelProviderRector;
use AlfaSix\Alfakit\Migration\Rector\FilamentTableActionsRector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withRules([
        FilamentNamespaceRector::class,
        FilamentIconsAndColorsRector::class,
        FilamentPanelProviderRector::class,
        FilamentTableActionsRector::class,
        FilamentClassMembersRector::class,
        FilamentLivewireFormRector::class,
        FilamentMethodsRector::class,
    ])
    ->withConfiguredRule(RenameClassRector::class, FilamentClassMap::classes())
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
