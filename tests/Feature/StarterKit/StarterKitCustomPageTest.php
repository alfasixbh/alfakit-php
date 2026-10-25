<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Route;

class StarterKitReportsPage extends Page
{
    protected static ?string $slug = 'reports';

    protected static ?string $component = 'Pages/Reports';
}

beforeEach(function (): void {
    Route::middleware('web')->get('/login', fn () => 'login')->name('login');
    app('router')->getRoutes()->refreshNameLookups();
    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));
});

it('renders a page with its own component through Alfakit/Page in a starter kit', function (): void {
    // The kit resolves components from resources/js/pages, which has only Alfakit/Page.
    Panels::register('kitpage', fn (Panel $panel): Panel => $panel->starterKit()->pages([StarterKitReportsPage::class]));

    $response = inertiaGet($this, '/kitpage/reports')->assertOk();

    expect($response->json('component'))->toBe('Alfakit/Page')
        ->and($response->json('props.page.view.component'))->toBe('Pages/Reports');
});

it('renders the page\'s own component directly in a panel', function (): void {
    Panels::register('ownpage', fn (Panel $panel): Panel => $panel->pages([StarterKitReportsPage::class]));

    expect(inertiaGet($this, '/ownpage/reports')->assertOk()->json('component'))->toBe('Pages/Reports');
});
