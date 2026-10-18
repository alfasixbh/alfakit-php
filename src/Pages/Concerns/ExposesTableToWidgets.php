<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

/**
 * Filament compatibility marker. In AlfaKit every page with a table already exposes it to
 * its widgets: a widget using `Widgets\Concerns\InteractsWithPageTable` reads the page table
 * (tab, filters, search) without anything on the page. Using this trait is harmless; it is
 * kept so code migrated from Filament (`use ExposesTableToWidgets;`) compiles unchanged.
 */
trait ExposesTableToWidgets {}
