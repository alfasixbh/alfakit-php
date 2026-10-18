<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Theming\MobileNavigation;
use AlfaSix\Alfakit\Theming\Theme;
use AlfaSix\Alfakit\Theming\Themes;

/*
 * The mobile preset is a design dimension (`mobile`): panels opt in with `->mobile()` or
 * `->design(mobile: 'bottom')`, themes with `Theme::mobile()`. Renderers read it from the
 * theme schema and from `data-mobile` on `<html>`.
 */

function resetMobilePanel(): AlfaSix\Alfakit\Panel
{
    $panel = alfakit()->getPanel('admin');
    (fn () => $this->designOverrides = [])->call($panel);
    (fn () => $this->theme = null)->call($panel);

    return $panel;
}

afterEach(function (): void {
    resetMobilePanel();
    Themes::flush();
});

it('defaults to the drawer below the md breakpoint', function (): void {
    $panel = resetMobilePanel();

    expect($panel->getDesign()['mobile'])->toBe('drawer')
        ->and($panel->getMobileNavigation())->toBe(MobileNavigation::Drawer)
        ->and($panel->getHtmlThemeAttributes()['data-mobile'])->toBe('drawer')
        ->and($panel->getThemeSchema()['design']['mobile'])->toBe('drawer');
});

it('enables the mobile preset with mobile()', function (): void {
    $panel = resetMobilePanel()->mobile();

    expect($panel->getDesign()['mobile'])->toBe('bottom')
        ->and($panel->getMobileNavigation())->toBe(MobileNavigation::Bottom)
        ->and($panel->getHtmlThemeAttributes()['data-mobile'])->toBe('bottom')
        ->and($panel->getThemeSchema()['design']['mobile'])->toBe('bottom');

    expect($panel->mobile(false)->getDesign()['mobile'])->toBe('drawer');
});

it('accepts the mobile dimension in design() as a string or enum', function (): void {
    $panel = resetMobilePanel();

    expect($panel->design(mobile: 'bottom')->getDesign()['mobile'])->toBe('bottom')
        ->and($panel->design(mobile: MobileNavigation::Drawer)->getDesign()['mobile'])->toBe('drawer');

    expect(fn () => $panel->design(mobile: 'floating'))->toThrow(InvalidArgumentException::class, 'drawer, bottom');
});

it('lets a theme choose the mobile preset and a panel override it', function (): void {
    Themes::register(Theme::make('pocket')->extends('alfakit')->mobile(MobileNavigation::Bottom));

    $panel = resetMobilePanel()->theme('pocket');

    expect($panel->getDesign()['mobile'])->toBe('bottom')
        ->and($panel->design(mobile: 'drawer')->getDesign()['mobile'])->toBe('drawer');
});
