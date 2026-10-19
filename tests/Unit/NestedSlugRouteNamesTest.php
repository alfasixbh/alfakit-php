<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;

/**
 * Route names with "/" (from nested slugs such as `helpdesk/inbox` or `cms/pages`) break
 * tools that turn route names into code, like the starter kits' Wayfinder.
 */
it('turns nested slugs into dotted route names', function (): void {
    $page = new class extends Page
    {
        protected static ?string $slug = 'helpdesk/inbox';
    };

    $resource = new class extends PostResource
    {
        protected static ?string $slug = 'cms/pages';
    };

    expect($page::getRelativeRouteName())->toBe('pages.helpdesk.inbox')
        ->and($resource::getRouteBaseName())->toBe('resources.cms.pages')
        ->and($page::getSlug())->toBe('helpdesk/inbox');
});
