<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\TableQueryString;
use Illuminate\Http\Request;

it('takes the list tab and table state from the page query string, nothing else', function (): void {
    $request = Request::create('/_alfakit/actions', 'POST', ['tableQuery' => '?activeTab=published&search=ada&filters[status]=draft&token=forged&posts_sort=title']);

    TableQueryString::apply($request);

    expect($request->query('activeTab'))->toBe('published')
        ->and($request->query('search'))->toBe('ada')
        ->and($request->query('filters'))->toBe(['status' => 'draft'])
        ->and($request->query('posts_sort'))->toBe('title')
        ->and($request->query('token'))->toBeNull();
});
