<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;

it('does not share resolved components between panel instances with the same id', function (): void {
    $first = Panel::make('cache-test')->resources([PostResource::class]);
    $second = Panel::make('cache-test');

    expect($first->getResources())->toBe([PostResource::class])
        ->and($second->getResources())->toBe([]);
});

it('re-resolves components after the registration changes or the cache is flushed', function (): void {
    $panel = Panel::make('cache-test-2')->resources([PostResource::class]);

    expect($panel->getResources())->toBe([PostResource::class]);

    $panel->resources([ProjectResource::class]);

    expect($panel->getResources())->toBe([PostResource::class, ProjectResource::class])
        ->and($panel->flushComponentCache()->getResources())->toBe([PostResource::class, ProjectResource::class]);
});
