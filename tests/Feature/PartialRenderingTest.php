<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\SchemaDiff;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\LiveTotalsForm;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * `partiallyRenderComponentsAfterStateUpdated()`, `partiallyRenderAfterStateUpdated()` and
 * `skipRenderAfterStateUpdated()` limit what a live update re-sends on top of the
 * fingerprint diff.
 */

beforeEach(function (): void {
    Route::middleware('web')->get('/live-totals', fn () => Inertia::render('Totals', ['form' => LiveTotalsForm::make()]));
});

/**
 * @return array{token: string, block: array<string, mixed>}
 */
function liveTotalsPage(AlfaSix\Alfakit\Tests\TestCase $test): array
{
    $props = inertiaProps(inertiaGet($test, '/live-totals'));

    return ['token' => $props['form']['token'], 'block' => $props['form']['content'][0]];
}

/**
 * @param  array<string, mixed>  $state
 * @param  array<string, mixed>  $fingerprints
 * @return array<string, mixed>
 */
function liveTotalsRefresh(string $token, array $state, string $path, array $fingerprints): array
{
    return test()->postJson('/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'form',
        'state' => $state,
        'path' => $path,
        'fingerprints' => $fingerprints,
    ])->assertOk()->json();
}

it('re-sends only the listed components, with the state the hooks set', function (): void {
    ['token' => $token, 'block' => $block] = liveTotalsPage($this);
    $state = [...$block['state'], 'quantity' => '3'];

    $response = liveTotalsRefresh($token, $state, 'quantity', $block['fingerprints']);

    // `note` (inside the Section) also changed its helper text, but only `total` is sent.
    expect($response['partial'])->toBeTrue()
        ->and(array_column(array_column($response['patches'], 'component'), 'statePath'))->toBe(['total'])
        ->and($response['patches'][0]['component']['helperText'])->toBe('Quantity 3')
        ->and($response['state'])->toBe(['total' => 30]);

    // The section keeps the fingerprint of what the browser shows, so the next normal
    // refresh still sends it.
    $notes = (string) array_search('notes', array_column($block['components'], 'key'), true);

    expect($response['fingerprints'][$notes])->toBe($block['fingerprints'][$notes])
        ->and($response['fingerprints']['1'])->not->toBe($block['fingerprints']['1']);
});

it('skips re-rendering but keeps $set() changes, or re-renders the field alone', function (): void {
    ['token' => $token, 'block' => $block] = liveTotalsPage($this);

    $skipped = liveTotalsRefresh($token, [...$block['state'], 'silent' => 'x', 'quantity' => '9'], 'silent', $block['fingerprints']);

    expect($skipped['patches'])->toBe([])
        ->and($skipped['state'])->toBe(['total' => 1])
        ->and($skipped['fingerprints'])->toBe($block['fingerprints']);

    $self = liveTotalsRefresh($token, [...$block['state'], 'self' => 'me', 'quantity' => '9'], 'self', $block['fingerprints']);

    expect(array_column(array_column($self['patches'], 'component'), 'statePath'))->toBe(['self'])
        ->and($self['patches'][0]['component']['helperText'])->toBe('Self me');
});

it('re-sends the repeater for a target inside one of its items', function (): void {
    ['token' => $token, 'block' => $block] = liveTotalsPage($this);
    $lines = $block['state']['lines'];
    $key = array_key_first($lines);
    $lines[$key]['qty'] = '4';

    $response = liveTotalsRefresh($token, [...$block['state'], 'lines' => $lines, 'quantity' => '9'], "lines.{$key}.qty", $block['fingerprints']);

    expect(array_column($response['patches'], 'path'))->each->toBeString()
        ->and(array_column(array_column($response['patches'], 'component'), 'statePath'))->toBe(['lines']);
});

it('makes no live requests for skipped fields without afterStateUpdated()', function (): void {
    $components = collect(liveTotalsPage($this)['block']['components'])->keyBy('name');

    expect($components['quiet'])->not->toHaveKey('live')
        ->and($components['silent'])->toHaveKey('live')
        ->and($components['self'])->toHaveKey('live');
});

it('resolves render targets: sibling names, keys and absolute paths', function (): void {
    $field = TextInput::make('a')->live()->partiallyRenderComponentsAfterStateUpdated(['b', 'a', 'notes', 'other.path']);
    schema([$field, TextInput::make('b')])->fill();

    expect($field->getPartialRenderTargets())->toBe(['b', 'a', 'notes', 'other.path'])
        ->and(TextInput::make('c')->live()->getPartialRenderTargets())->toBeNull()
        ->and(TextInput::make('d')->skipRenderAfterStateUpdated(fn (): bool => false)->getPartialRenderTargets())->toBeNull();
});

it('limits a diff to the target components without fingerprints falling back to the full payload', function (): void {
    $payload = ['components' => [['type' => 'TextInput', 'key' => 'a', 'statePath' => 'a', 'label' => 'A']], 'state' => ['a' => 1]];

    expect(SchemaDiff::respond($payload, null, ['a' => 1], []))->toBe($payload);
});
