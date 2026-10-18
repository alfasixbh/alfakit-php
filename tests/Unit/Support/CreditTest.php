<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\Concerns\HasPoweredByCredit;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Notifications\Messages\MailMessage;

it('keeps the powered-by credit off customer pages and emails by default', function (): void {
    expect(Credit::onPublicPages())->toBeFalse()
        ->and(Credit::inEmails())->toBeFalse()
        ->and(Credit::poweredByWhen())->toBeNull()
        ->and(Credit::emailLine())->toBeNull()
        ->and(Credit::addToMail(new MailMessage)->introLines)->toBe([]);
});

it('renders the translated powered-by line with the Alfa Six emblem', function (): void {
    $html = Credit::poweredBy()->toHtml();

    expect($html)->toContain('data-slot="powered-by"')
        ->toContain('Powered by <a href="https://alfakit.dev"')
        ->toContain('>AlfaKit</a> · <a href="https://alfasixbh.com"')
        ->toContain('>Alfa Six</a>')
        ->toContain('<svg style=')
        ->toContain('aria-hidden="true"')
        ->toContain('var(--alfakit-credit-emblem,#073b78)');

    // PDF engines get the emblem as an image and no flexbox.
    expect(Credit::poweredBy(embedded: true)->toHtml())
        ->toContain('<img src="data:image/svg+xml;base64,')
        ->not->toContain('<svg')
        ->not->toContain('display:flex');
});

it('turns the credit on from the config', function (): void {
    config(['alfakit.credit.public_pages' => true, 'alfakit.credit.emails' => true]);

    expect(Credit::poweredByWhen())->toContain('data-slot="powered-by"')
        ->and(Credit::emailLine())->toBe('Powered by [AlfaKit](https://alfakit.dev) · [Alfa Six](https://alfasixbh.com)')
        ->and(Credit::addToMail((new MailMessage)->action('Open', 'https://example.com'))->outroLines)->toBe(['Powered by [AlfaKit](https://alfakit.dev) · [Alfa Six](https://alfasixbh.com)']);
});

it('lets a plugin override the config with poweredBy()', function (): void {
    $plugin = new class
    {
        use HasPoweredByCredit;
    };

    expect($plugin->hasPoweredBy())->toBeFalse()
        ->and($plugin->getPoweredByHtml())->toBeNull()
        ->and($plugin->getPoweredByEmailLine())->toBeNull();

    $plugin->poweredBy();
    expect($plugin->getPoweredByHtml())->toContain('data-slot="powered-by"')
        ->and($plugin->getPoweredByEmailLine())->toContain('[AlfaKit]');

    config(['alfakit.credit.public_pages' => true]);
    $plugin->poweredBy(fn (): bool => false);
    expect($plugin->hasPoweredBy())->toBeFalse();

    $plugin->poweredBy(null);
    expect($plugin->hasPoweredBy())->toBeTrue()
        ->and($plugin->hasPoweredByInEmails())->toBeFalse();
});
