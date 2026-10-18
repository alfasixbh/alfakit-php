<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditShopProfile;
use App\Filament\Pages\Tenancy\RegisterShop;
use App\Models\Shop;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Enums\GlobalSearchPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Kirschbaum\Commentions\CommentionsPlugin;
use Mokhosh\FilamentKanban\FilamentKanbanPlugin;
use Rmsramos\Activitylog\ActivitylogPlugin;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;
use SolutionForest\FilamentTree\FilamentTreePlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ])
            ->brandName('Acme Shop')
            ->favicon(asset('favicon.svg'))
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'warning' => Color::hex('#f59e0b'),
            ])
            ->font('Inter')
            ->maxContentWidth(Width::SevenExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->unsavedChangesAlerts()
            ->globalSearch(position: GlobalSearchPosition::Topbar)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->tenant(Shop::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterShop::class)
            ->tenantProfile(EditShopProfile::class)
            ->navigationGroups([
                NavigationGroup::make('Shop')->icon(Heroicon::OutlinedShoppingBag),
                NavigationGroup::make('Blog')->icon('heroicon-o-newspaper'),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Storefront')
                    ->url('https://shop.acme.test', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-globe-alt')
                    ->group('Shop')
                    ->sort(99),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Documentation')
                    ->url('https://docs.acme.test')
                    ->icon(Heroicon::OutlinedBookOpen),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
                CommentionsPlugin::make(),
                FilamentKanbanPlugin::make(),
                FilamentFullCalendarPlugin::make()->selectable()->editable(),
                ActivitylogPlugin::make()->navigationGroup('Settings'),
                FilamentTreePlugin::make(),
            ])
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => view('partials.announcement')->render())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Blade::render('<span class="text-xs">{{ now()->format(\'H:i\') }}</span>'))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn (): string => view('partials.sidebar-help')->render())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
