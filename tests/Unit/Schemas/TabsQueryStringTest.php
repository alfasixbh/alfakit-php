<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;

/**
 * @param  array<int, Tab>  $children
 * @return array<int, array<string, mixed>>
 */
function queryStringTabs(Tabs $tabs, array $children): array
{
    $schema = schema([$tabs->tabs($children)])->fill()->toSchema();

    return $schema[0]['schema'];
}

it('persists tabs in the query string under readable values', function (): void {
    $tabs = queryStringTabs(Tabs::make()->persistTabInQueryString(), [
        Tab::make('Billing address')->schema([TextInput::make('street')]),
        Tab::make('Shipping')->id('ship'),
        Tab::make('Notes')->key('internal-notes'),
        Tab::make('Billing address'),
        Tab::make('Billing address'),
    ]);

    expect(array_column($tabs, 'queryValue'))->toBe(['billing-address', 'ship', 'internal-notes', 'billing-address-2', 'billing-address-3'])
        // The component keys stay the internal ones.
        ->and($tabs[0]['key'])->not->toBe('billing-address');
});

it('sends no query values for tabs not kept in the query string', function (): void {
    $tabs = queryStringTabs(Tabs::make(), [Tab::make('Billing address')]);

    expect($tabs[0])->not->toHaveKey('queryValue');
});

it('falls back to the tab position when a label has no slug', function (): void {
    $tabs = queryStringTabs(Tabs::make()->persistTabInQueryString('section'), [Tab::make('…'), Tab::make('Details')]);

    expect($tabs[0]['queryValue'])->toBe('tab-0')
        ->and($tabs[1]['queryValue'])->toBe('details');
});
