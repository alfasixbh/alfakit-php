<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Navigation\NavigationItem;

it('marks every ancestor of an active nested item active and keeps child options', function (): void {
    $schema = NavigationItem::make('Shop')->childItems([
        NavigationItem::make('Catalog')->childItems([
            NavigationItem::make('Products')->isActiveWhen(fn (): bool => true)->badge(3, 'danger'),
        ]),
        NavigationItem::make('Docs')->url('https://example.com')->openUrlInNewTab(),
    ])->toSchema();

    expect($schema['active'])->toBeTrue()
        ->and($schema['children'][0]['active'])->toBeTrue()
        ->and($schema['children'][0]['children'][0])->toMatchArray(['label' => 'Products', 'badge' => 3, 'badgeColor' => 'danger', 'active' => true])
        ->and($schema['children'][1]['openInNewTab'])->toBeTrue()
        ->and($schema['children'][1])->not->toHaveKey('active');
});
