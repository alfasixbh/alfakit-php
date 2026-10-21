<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource;
use Illuminate\Routing\Router;

class PathConflictCoursesPage extends Page
{
    protected static ?string $slug = 'courses';
}

class PathConflictReportsPage extends Page
{
    protected static ?string $slug = 'reports';
}

class PathConflictOtherReportsPage extends Page
{
    protected static ?string $slug = 'reports';
}

function registerPathConflictPanel(string $id, array $pages, array $resources = []): void
{
    $panel = Panel::make()->id($id)->path($id)->middleware(['web'])->pages($pages)->resources($resources);
    alfakit()->registerPanel($panel);
    $panel->registerRoutes(app(Router::class));
}

it('names both classes when a page and a resource share a path', function (): void {
    registerPathConflictPanel('clasha', [PathConflictCoursesPage::class], [CourseResource::class]);
})->throws(LogicException::class, '['.PathConflictCoursesPage::class.'] and ['.CourseResource::class.'] both use the path /clasha/courses on the [clasha] panel');

it('names both classes when two pages share a path', function (): void {
    registerPathConflictPanel('clashb', [PathConflictReportsPage::class, PathConflictOtherReportsPage::class]);
})->throws(LogicException::class, 'both use the path /clashb/reports');

it('registers pages and resources on different paths', function (): void {
    registerPathConflictPanel('clashc', [PathConflictReportsPage::class], [CourseResource::class]);

    app(Router::class)->getRoutes()->refreshNameLookups();

    expect(app(Router::class)->has('alfakit.clashc.pages.reports'))->toBeTrue();
});
