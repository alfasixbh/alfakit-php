<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

it('adds the Vite CSP nonce to the root view\'s inline script and style', function (): void {
    Vite::useCspNonce('alfakit-test-nonce');

    $html = (string) $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toMatch('/<script\s+nonce="alfakit-test-nonce"\s*>/')
        ->and($html)->toMatch('/<style\s+nonce="alfakit-test-nonce"\s*>/');
});

it('omits the nonce attribute when no CSP nonce is set', function (): void {
    $html = (string) $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toContain('nonce=')
        ->and($html)->toMatch('/<script\s*>/')
        ->and($html)->toMatch('/<style\s*>/');
});
