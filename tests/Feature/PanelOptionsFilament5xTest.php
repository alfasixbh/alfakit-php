<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Contracts\HasName;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/*
 * Filament 5.1-5.10.2 panel options: `darkMode()` closures, font preloading and Htmlable
 * tenant names.
 */

class HtmlNamedTeam extends Team implements HasName
{
    protected $table = 'teams';

    public function getAlfakitName(): string|Htmlable
    {
        return new HtmlString(e($this->getAttribute('name')).' <strong>Pro</strong><img src=x onerror=alert(1)>');
    }
}

it('resolves darkMode() closures per call', function (): void {
    $enabled = true;
    $panel = Panel::make('dark')->darkMode(function () use (&$enabled): bool {
        return $enabled;
    }, fn (): bool => false);

    expect($panel->hasDarkMode())->toBeTrue()
        ->and($panel->hasDarkModeForced())->toBeFalse();

    $enabled = false;

    expect($panel->hasDarkMode())->toBeFalse()
        ->and($panel->resolveAppearance('dark'))->toBe('light');
});

it('emits preload links for font(preload: [...]) and drops unsafe URLs', function (): void {
    $panel = Panel::make('fonts')
        ->font('Inter', url: '/fonts/inter.css', preload: ['/fonts/inter-latin.woff2', 'javascript:alert(1)'])
        ->headingFont('Sora', url: '/fonts/sora.css', preload: fn (): array => ['https://cdn.test/sora.woff']);

    expect($panel->getFontPreload())->toBe(['/fonts/inter-latin.woff2', 'https://cdn.test/sora.woff'])
        ->and($panel->getFontPreloadHtml()->toHtml())
        ->toContain('<link rel="preload" href="/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>')
        ->toContain('href="https://cdn.test/sora.woff" as="font" type="font/woff"')
        ->not->toContain('javascript')
        ->and(Panel::make('none')->getFontPreloadHtml()->toHtml())->toBe('');
});

it('renders the preload links in the root view', function (): void {
    alfakit()->getPanel('admin')->font('Inter', url: '/fonts/inter.css', preload: ['/fonts/inter-latin.woff2']);
    $this->actingAs(admin());

    try {
        expect($this->get('/admin')->getContent())->toContain('<link rel="preload" href="/fonts/inter-latin.woff2" as="font"');
    } finally {
        alfakit()->getPanel('admin')->font(null);
    }
});

it('serialises Htmlable tenant names as sanitised HTML and uses their text elsewhere', function (): void {
    $panel = Panel::make('tenantnames');
    $team = new HtmlNamedTeam(['name' => 'Acme & Co']);

    $schema = (fn (HtmlNamedTeam $tenant): array => $this->getTenantNameSchema($tenant))->call($panel, $team);

    expect($panel->getTenantLabel($team))->toBe('Acme & Co Pro')
        ->and($schema['nameHtml'])->toBeTrue()
        ->and($schema['name'])->toContain('<strong>Pro</strong>')->not->toContain('onerror')
        ->and((fn (Team $tenant): array => $this->getTenantNameSchema($tenant))->call($panel, new Team(['name' => '<b>Plain</b>'])))
        ->toBe(['name' => '<b>Plain</b>']);
});
