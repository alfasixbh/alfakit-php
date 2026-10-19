<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Resources\Pages\Page as ResourcePage;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/*
 * Opens every GET page of a panel with real records in the URL, signed in, and fails on
 * any server error. Catches a page (or the panel home) that crashes, such as a link to a
 * nested resource's list without its parent record.
 */

it('opens every page of the panel without a server error', function (): void {
    ArPanelProvider::registerPanel();
    $post = Post::query()->create(['title' => 'Crawl', 'slug' => 'crawl-'.uniqid()]);
    $comment = $post->comments()->create(['author_name' => 'Ann', 'body' => 'Hello']);
    $this->actingAs(User::query()->create(['name' => 'Crawler', 'email' => 'crawl'.uniqid().'@example.com', 'password' => 'password']));

    $records = [Post::class => $post, $comment::class => $comment];
    $failures = [];
    $visited = 0;

    foreach (app('router')->getRoutes() as $route) {
        /** @var Route $route */
        $uri = $route->uri();

        if (! in_array('GET', $route->methods(), true) || ! Str::startsWith($uri, 'ar/') && $uri !== 'ar' || Str::contains($uri, ['_alfakit', 'logout', 'invitations', 'impersonation', 'email/verify', 'reset-password'])) {
            continue;
        }

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $class = $route->getControllerClass();
            $model = is_string($class) && is_subclass_of($class, ResourcePage::class) && $name === 'record'
                ? $class::getResource()::getModel()
                : null;

            // The record's own model, else the parent the parameter is named after (`{post}`).
            $record = $model !== null ? ($records[$model] ?? null) : ($records[Post::class] ?? null);
            $parameters[$name] = $record?->getKey() ?? 1;
        }

        $url = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => (string) ($parameters[$match[1]] ?? ''), $uri) ?? $uri, '/');
        $status = $this->get($url)->getStatusCode();
        $visited++;

        if ($status >= 500) {
            $failures[] = "{$status} {$url}";
        }
    }

    expect($visited)->toBeGreaterThan(5)
        ->and($failures)->toBe([]);
});
