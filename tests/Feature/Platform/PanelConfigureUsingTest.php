<?php

declare(strict_types=1);

use AlfaSix\Alfakit\AlfakitManager;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;

afterEach(function (): void {
    Panel::clearConfigurations();
});

it('runs Panel::configureUsing() callbacks with each provider panel\'s real id', function (): void {
    $seen = [];

    Panel::configureUsing(function (Panel $panel) use (&$seen): void {
        $seen[] = $panel->getId();

        if ($panel->getId() === 'crm') {
            $panel->brandName('CRM from module');
        }
    });

    $billing = new class(app()) extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel->id('billing')->path('billing')->brandName('Billing');
        }
    };

    $crm = new class(app()) extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel->id('crm')->path('crm')->brandName('CRM');
        }
    };

    $billing->register();
    $crm->register();

    $manager = app(AlfakitManager::class);

    expect($seen)->toBe(['billing', 'crm'])
        ->and($manager->getPanel('crm')->getBrandName())->toBe('CRM from module')
        ->and($manager->getPanel('billing')->getBrandName())->toBe('Billing');
});
