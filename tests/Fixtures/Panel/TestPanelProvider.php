<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Panel;

use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Widgets\PostStatsWidget;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile()
            ->brandName('Alfakit Test')
            ->colors(['primary' => '#2563eb'])
            ->middleware(['web'])
            ->resources([PostResource::class])
            ->pages([Dashboard::class, Pages\PlansPage::class])
            ->widgets([PostStatsWidget::class])
            ->navigationGroups(['Content'])
            ->userMenuItems([MenuItem::make()->label('Docs')->url('https://alfakit.dev')->icon('book')])
            ->databaseNotifications();
    }
}
